<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

/**
 * Pure scoring engine. No DB, no HTTP — takes Bottle/RecipeSlot domain
 * objects and returns Assessments.
 *
 * Port of flavor.py's assess + alternatives_for_slot + uses_for_bottle +
 * find_gaps. Same penalty math: lower is better, hard-Band breaches
 * disqualify (sorted to bottom unless include_strays=true).
 */
final class Engine
{
    /**
     * Score one bottle against one slot.
     *
     * Point  → weight * |have - value|, flagged when nonzero.
     * Band   → 0 inside [lo,hi]; outside, out_weight * distance-to-edge.
     *          With hard=true, breach also sets disqualified=true.
     *
     * Cross-category match adds a flat crossCategoryPenalty so in-category
     * candidates rank first.
     */
    public function assess(Bottle $bottle, RecipeSlot $slot): Assessment
    {
        $penalty = 0.0;
        $disqualified = false;
        $flags = [];
        $crossCategory = $bottle->category !== $slot->category;

        if ($crossCategory) {
            $penalty += $slot->crossCategoryPenalty;
            $flags[] = "cross-category: {$bottle->category} subbing for {$slot->category}";
        }

        foreach ($slot->constraints as $axis => $c) {
            $have = $bottle->profile[$axis] ?? 0;

            if ($c->kind === 'point') {
                $d = abs($have - (int) $c->pointValue);
                if ($d > 0) {
                    $penalty += $c->weight * $d;
                    $flags[] = "{$axis} {$have} vs target {$c->pointValue}";
                }
            } else { // band
                if ($have < $c->bandLo) {
                    $gap = $c->bandLo - $have;
                    $penalty += $c->outWeight * $gap;
                    $flags[] = "{$axis} {$have} below comfort band {$c->bandLo}-{$c->bandHi}";
                    if ($c->hard) {
                        $disqualified = true;
                    }
                } elseif ($have > $c->bandHi) {
                    $gap = $have - $c->bandHi;
                    $penalty += $c->outWeight * $gap;
                    $tag = $c->hard ? ' — likely to fight the drink' : '';
                    $flags[] = "{$axis} {$have} above comfort band {$c->bandLo}-{$c->bandHi}{$tag}";
                    if ($c->hard) {
                        $disqualified = true;
                    }
                }
            }
        }

        return new Assessment($penalty, $disqualified, $flags, $crossCategory);
    }

    /**
     * Rank in-stock bottles by fit for a recipe slot.
     *
     * - tolerance=exact: returns only the named bottle if present + in stock.
     * - tolerance=style: in-category (or alsoAcceptCategories) bottles ranked
     *   by assess(). Strays (disqualified) hidden unless $includeStrays=true.
     * - tolerance=any: any in-category in-stock bottle, distance 0.
     *
     * @param list<Bottle> $bottles
     * @return list<array{bottle: Bottle, assessment: Assessment}>
     */
    public function alternativesForSlot(array $bottles, RecipeSlot $slot, int $topN = 10, bool $includeStrays = false): array
    {
        if ($slot->tolerance === 'exact') {
            foreach ($bottles as $b) {
                if ($b->id === $slot->exactIngredientId && $b->inStock) {
                    return [['bottle' => $b, 'assessment' => new Assessment(0.0, false, [])]];
                }
            }
            return [];
        }

        $categories = $slot->eligibleCategories();
        $pool = array_values(array_filter(
            $bottles,
            fn (Bottle $b) => in_array($b->category, $categories, true)
                && $b->inStock
                && $this->proofOk($b, $slot)
        ));

        if ($slot->tolerance === 'any' || empty($slot->constraints)) {
            usort($pool, fn (Bottle $a, Bottle $b) => $a->name <=> $b->name);
            $picks = array_slice($pool, 0, $topN);
            return array_map(
                fn (Bottle $b) => ['bottle' => $b, 'assessment' => new Assessment(0.0, false, [])],
                $picks
            );
        }

        $results = [];
        foreach ($pool as $b) {
            $a = $this->assess($b, $slot);
            if ($a->disqualified && !$includeStrays) {
                continue;
            }
            $results[] = ['bottle' => $b, 'assessment' => $a];
        }

        usort($results, function ($x, $y) {
            $dx = $x['assessment']->disqualified ? 1 : 0;
            $dy = $y['assessment']->disqualified ? 1 : 0;
            return [$dx, $x['assessment']->penalty, $x['bottle']->name]
                <=> [$dy, $y['assessment']->penalty, $y['bottle']->name];
        });

        return array_slice($results, 0, $topN);
    }

    /**
     * Given a bottle, rank slots whose constraints it satisfies.
     *
     * @param list<RecipeSlot> $slots
     * @return list<array{slot: RecipeSlot, assessment: Assessment}>
     */
    public function usesForBottle(Bottle $bottle, array $slots, int $topN = 10): array
    {
        $scored = [];
        foreach ($slots as $s) {
            if (!in_array($bottle->category, $s->eligibleCategories(), true)) {
                continue;
            }
            if (!$this->proofOk($bottle, $s)) {
                continue;
            }
            $a = $this->assess($bottle, $s);
            $scored[] = ['slot' => $s, 'assessment' => $a];
        }

        usort($scored, function ($x, $y) {
            $dx = $x['assessment']->disqualified ? 1 : 0;
            $dy = $y['assessment']->disqualified ? 1 : 0;
            return [$dx, $x['assessment']->penalty, $x['slot']->cocktailId]
                <=> [$dy, $y['assessment']->penalty, $y['slot']->cocktailId];
        });

        return array_slice($scored, 0, $topN);
    }

    /**
     * Slots in the wishlist where the best in-stock match is a stretch.
     *
     * @param list<Bottle> $bottles
     * @param list<RecipeSlot> $wishlistSlots
     * @return list<array{slot: RecipeSlot, bottle: ?Bottle, penalty: float, reason: string}>
     */
    public function findGaps(array $bottles, array $wishlistSlots, float $threshold = 3.0): array
    {
        $gaps = [];
        foreach ($wishlistSlots as $s) {
            $best = $this->alternativesForSlot($bottles, $s, topN: 1, includeStrays: true);
            if (empty($best)) {
                $gaps[] = ['slot' => $s, 'bottle' => null, 'penalty' => INF, 'reason' => 'nothing in stock in this category'];
                continue;
            }
            $b = $best[0]['bottle'];
            $a = $best[0]['assessment'];
            if ($a->disqualified || $a->penalty >= $threshold) {
                $why = empty($a->flags) ? 'weak match' : implode('; ', $a->flags);
                $gaps[] = ['slot' => $s, 'bottle' => $b, 'penalty' => $a->penalty, 'reason' => $why];
            }
        }

        usort($gaps, fn ($x, $y) => $y['penalty'] <=> $x['penalty']);
        return $gaps;
    }

    private function proofOk(Bottle $bottle, RecipeSlot $slot): bool
    {
        if ($slot->proofMin !== null && ($bottle->proof === null || $bottle->proof < $slot->proofMin)) {
            return false;
        }
        if ($slot->proofMax !== null && ($bottle->proof === null || $bottle->proof > $slot->proofMax)) {
            return false;
        }
        return true;
    }
}
