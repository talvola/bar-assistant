<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services\Flavor;

use Kami\Cocktail\Models\Flavor\CategoryAxes;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;
use Kami\Cocktail\Models\Flavor\SlotConstraint;
use Kami\Cocktail\Models\Flavor\SlotMeta;
use Kami\Cocktail\Models\Ingredient;

/**
 * Bridge between Eloquent rows and the pure scoring Engine. Loads Bottle /
 * RecipeSlot objects from the database, then delegates to Engine for scoring.
 *
 * Replaces flavor_db.py's load_bottles / load_slot / load_all_slots.
 */
final class FlavorService
{
    public function __construct(private readonly Engine $engine = new Engine())
    {
    }

    /**
     * Build a Bottle from an ingredient_id, pulling its profile rows.
     * Returns null if the ingredient has no profile.
     */
    public function loadBottle(int $ingredientId, bool $inStock = true): ?Bottle
    {
        $ingredient = Ingredient::find($ingredientId);
        if (!$ingredient) {
            return null;
        }

        $profileRows = IngredientProfile::where('ingredient_id', $ingredientId)->get();
        if ($profileRows->isEmpty()) {
            return null;
        }

        $profile = [];
        $latestSource = '';
        $latestConfidence = '';
        $latestNotes = '';
        $latestDate = '';
        $suggestable = true;

        foreach ($profileRows as $row) {
            $profile[$row->axis] = $row->value;
            $scoredStr = $row->scored_at ? $row->scored_at->toDateString() : '';
            if ($scoredStr > $latestDate) {
                $latestDate = $scoredStr;
                $latestSource = $row->source ?? '';
                $latestConfidence = $row->confidence ?? '';
                $latestNotes = $row->notes ?? '';
            }
            // suggestable_for_classics is per-profile-row but should be the
            // same across axes — take the most-restrictive (any false → false).
            $suggestable = $suggestable && (bool) $row->suggestable_for_classics;
        }

        $category = $this->categoryFor($ingredient);
        $proof = $ingredient->strength !== null ? (float) $ingredient->strength * 2.0 : null;

        return new Bottle(
            id: $ingredient->id,
            name: $ingredient->name,
            category: $category,
            profile: $profile,
            proof: $proof,
            inStock: $inStock,
            source: $latestSource,
            confidence: $latestConfidence,
            notes: $latestNotes,
            suggestableForClassics: $suggestable,
        );
    }

    /**
     * Load every bottle in the current bar that has a profile, optionally
     * filtered to a category. Pre-filters by IngredientCategory when a
     * category is given so we avoid building Bottle objects we'd discard.
     *
     * @return list<Bottle>
     */
    public function loadBottles(?string $category = null): array
    {
        $q = IngredientProfile::query()
            ->select('flavor_ingredient_profiles.ingredient_id')
            ->join('ingredients', 'ingredients.id', '=', 'flavor_ingredient_profiles.ingredient_id')
            ->where('ingredients.bar_id', bar()->id)
            ->distinct();

        if ($category !== null) {
            $q->join('flavor_ingredient_categories', 'flavor_ingredient_categories.ingredient_id', '=', 'flavor_ingredient_profiles.ingredient_id')
                ->where('flavor_ingredient_categories.category', $category);
        }

        $ingredientIds = $q->pluck('ingredient_id')->all();

        $bottles = [];
        foreach ($ingredientIds as $id) {
            $b = $this->loadBottle((int) $id);
            if ($b !== null) {
                $bottles[] = $b;
            }
        }
        return $bottles;
    }

    /**
     * Build a RecipeSlot from (cocktail_id, sort). Returns null if no
     * slot_meta exists.
     */
    public function loadSlot(int $cocktailId, int $sort): ?RecipeSlot
    {
        $meta = SlotMeta::where('cocktail_id', $cocktailId)->where('sort', $sort)->first();
        if (!$meta) {
            return null;
        }

        $constraintRows = SlotConstraint::where('cocktail_id', $cocktailId)
            ->where('sort', $sort)
            ->get();

        $constraints = [];
        foreach ($constraintRows as $row) {
            $constraints[$row->axis] = new Constraint(
                kind: $row->kind,
                pointValue: $row->point_value,
                bandLo: $row->band_lo,
                bandHi: $row->band_hi,
                weight: (float) $row->weight,
                outWeight: (float) $row->out_weight,
                hard: (bool) $row->hard,
            );
        }

        return new RecipeSlot(
            cocktailId: $cocktailId,
            sort: $sort,
            category: $meta->category,
            tolerance: $meta->tolerance,
            exactIngredientId: $meta->exact_ingredient_id,
            constraints: $constraints,
            alsoAcceptCategories: $meta->alsoAccept(),
            proofMin: $meta->proof_min,
            proofMax: $meta->proof_max,
        );
    }

    /**
     * Load every slot in the current bar (i.e. cocktails belonging to this bar).
     *
     * @return list<RecipeSlot>
     */
    public function loadAllSlots(): array
    {
        $pairs = SlotMeta::query()
            ->select('flavor_slot_metas.cocktail_id', 'flavor_slot_metas.sort')
            ->join('cocktails', 'cocktails.id', '=', 'flavor_slot_metas.cocktail_id')
            ->where('cocktails.bar_id', bar()->id)
            ->orderBy('cocktail_id')
            ->orderBy('sort')
            ->get();

        $slots = [];
        foreach ($pairs as $row) {
            $s = $this->loadSlot((int) $row->cocktail_id, (int) $row->sort);
            if ($s !== null) {
                $slots[] = $s;
            }
        }
        return $slots;
    }

    /**
     * Resolve an ingredient's category from the flavor_ingredient_categories
     * side table. Returns '' if the ingredient hasn't been categorized.
     */
    private function categoryFor(Ingredient $ingredient): string
    {
        $cat = IngredientCategory::where('ingredient_id', $ingredient->id)->value('category');
        return is_string($cat) ? $cat : '';
    }
}
