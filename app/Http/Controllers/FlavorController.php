<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Flavor\CategoryAxes;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Services\Flavor\FlavorService;

/**
 * Read-only endpoints for the flavor-matching engine. Slice 1 of Phase B —
 * editing endpoints (PUT/DELETE) come in Slice 2/3.
 */
class FlavorController extends Controller
{
    public function __construct(private readonly FlavorService $service = new FlavorService())
    {
    }

    /**
     * GET /api/flavor/categories
     * → [{category: "gin", axes: ["juniper", "citrus", ...]}, ...]
     */
    public function categories(): JsonResponse
    {
        $data = CategoryAxes::all()
            ->map(fn (CategoryAxes $row) => [
                'category' => $row->category,
                'axes' => $row->axes(),
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/ingredients/{id}/flavor-profile
     * → {ingredient_id, category, profile, source, confidence, notes, scored_at, suggestable_for_classics}
     * Returns 404 if no profile rows.
     */
    public function ingredientProfile(int $id): JsonResponse
    {
        $ingredient = Ingredient::query()
            ->filterByBar()
            ->where('id', $id)
            ->first();
        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $rows = IngredientProfile::where('ingredient_id', $id)->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No flavor profile recorded for this ingredient'], 404);
        }

        $latest = $rows->sortByDesc(fn ($r) => $r->scored_at?->toDateString() ?? '')->first();
        $category = IngredientCategory::where('ingredient_id', $id)->value('category') ?? '';

        return response()->json([
            'data' => [
                'ingredient_id' => $id,
                'category' => $category,
                'profile' => $rows->mapWithKeys(fn ($r) => [$r->axis => (int) $r->value])->all(),
                'source' => $latest->source,
                'confidence' => $latest->confidence,
                'notes' => $latest->notes,
                'scored_at' => $latest->scored_at?->toDateString(),
                'suggestable_for_classics' => (bool) $latest->suggestable_for_classics,
            ],
        ]);
    }

    /**
     * GET /api/cocktails/{id}/slots/{sort}/alternatives
     *   ?on_shelf_only=true&include_strays=false&top_n=10
     * → ranked list with assessments.
     */
    public function alternativesForSlot(int $cocktailId, int $sort): JsonResponse
    {
        $cocktail = Cocktail::query()
            ->filterByBar()
            ->where('id', $cocktailId)
            ->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }

        $slot = $this->service->loadSlot($cocktailId, $sort);
        if (!$slot) {
            return response()->json(['message' => 'No slot_meta declared for this slot'], 404);
        }

        $onShelfOnly = request()->boolean('on_shelf_only', true);
        $includeStrays = request()->boolean('include_strays', false);
        $topN = (int) request()->integer('top_n', 10);

        $bottles = $this->service->loadBottles();

        if ($onShelfOnly) {
            $shelf = $this->shelfIngredientIds();
            $bottles = array_map(
                fn ($b) => new \Kami\Cocktail\Services\Flavor\Bottle(
                    id: $b->id, name: $b->name, category: $b->category, profile: $b->profile,
                    proof: $b->proof, inStock: in_array($b->id, $shelf, true),
                    source: $b->source, confidence: $b->confidence, notes: $b->notes,
                    suggestableForClassics: $b->suggestableForClassics,
                ),
                $bottles,
            );
        } else {
            $bottles = array_map(
                fn ($b) => new \Kami\Cocktail\Services\Flavor\Bottle(
                    id: $b->id, name: $b->name, category: $b->category, profile: $b->profile,
                    proof: $b->proof, inStock: true,
                    source: $b->source, confidence: $b->confidence, notes: $b->notes,
                    suggestableForClassics: $b->suggestableForClassics,
                ),
                $bottles,
            );
        }

        $engine = new \Kami\Cocktail\Services\Flavor\Engine();
        $results = $engine->alternativesForSlot($bottles, $slot, $topN, $includeStrays);

        $payload = array_map(function ($r) {
            return [
                'bottle' => [
                    'id' => $r['bottle']->id,
                    'name' => $r['bottle']->name,
                    'category' => $r['bottle']->category,
                    'confidence' => $r['bottle']->confidence,
                ],
                'penalty' => round($r['assessment']->penalty, 2),
                'disqualified' => $r['assessment']->disqualified,
                'verdict' => $r['assessment']->verdict(),
                'flags' => $r['assessment']->flags,
                'cross_category' => $r['assessment']->crossCategory,
            ];
        }, $results);

        return response()->json([
            'data' => [
                'cocktail_id' => $cocktailId,
                'sort' => $sort,
                'category' => $slot->category,
                'also_accept_categories' => $slot->alsoAcceptCategories,
                'tolerance' => $slot->tolerance,
                'alternatives' => $payload,
            ],
        ]);
    }

    /**
     * Pull in-stock ingredient_ids for the current bar via the BarIngredient
     * shelf relation. Returns a list<int>.
     *
     * @return list<int>
     */
    private function shelfIngredientIds(): array
    {
        return \Kami\Cocktail\Models\BarIngredient::query()
            ->where('bar_id', bar()->id)
            ->pluck('ingredient_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
