<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Flavor\CategoryAxes;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;
use Kami\Cocktail\Models\Flavor\SlotConstraint;
use Kami\Cocktail\Models\Flavor\SlotMeta;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\BarIngredient;
use Kami\Cocktail\Services\Flavor\Bottle;
use Kami\Cocktail\Services\Flavor\Engine;
use Kami\Cocktail\Services\Flavor\FlavorService;

/**
 * Endpoints for the flavor-matching engine. Slice 1 added read-only endpoints;
 * Slice 2 adds upserts for ingredient profiles + slot meta + slot constraints
 * so the Phase A SQLite can be ported in via scripts/port_phase_a_to_ba.py.
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
     * PUT /api/ingredients/{id}/flavor-profile
     *
     * Upsert the ingredient's category + per-axis profile + provenance.
     *
     * Body:
     *   {category, profile: {axis: value}, source?, confidence?, notes?,
     *    suggestable_for_classics?, scored_at?}
     *
     * Validates that the category exists in flavor_category_axes and that all
     * axes in `profile` belong to that category. Replaces all existing profile
     * rows for this ingredient (other-axis rows from a previous category get
     * deleted) — keeps the data clean across category changes.
     */
    public function putIngredientProfile(Request $request, int $id): JsonResponse
    {
        $ingredient = Ingredient::query()->filterByBar()->where('id', $id)->first();
        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $data = $request->validate([
            'category' => 'required|string|max:64',
            'profile' => 'required|array|min:1',
            'profile.*' => 'integer|min:0|max:3',
            'source' => 'nullable|string|max:32',
            'confidence' => 'nullable|string|in:high,medium,low',
            'notes' => 'nullable|string',
            'suggestable_for_classics' => 'nullable|boolean',
            'scored_at' => 'nullable|date_format:Y-m-d',
        ]);

        $axes = CategoryAxes::where('category', $data['category'])->first();
        if (!$axes) {
            return response()->json(['message' => "Unknown category '{$data['category']}'. Configure via flavor_category_axes."], 422);
        }
        $validAxes = $axes->axes();
        $unknown = array_diff(array_keys($data['profile']), $validAxes);
        if (!empty($unknown)) {
            return response()->json([
                'message' => 'Profile contains axes not in this category',
                'unknown_axes' => array_values($unknown),
                'valid_axes' => $validAxes,
            ], 422);
        }

        IngredientCategory::updateOrCreate(
            ['ingredient_id' => $id],
            ['category' => $data['category']],
        );

        // Replace strategy: drop all existing profile rows for this ingredient,
        // then write the new ones. Keeps the data clean if the category changes
        // (axes from the old category get evicted).
        IngredientProfile::where('ingredient_id', $id)->delete();
        foreach ($data['profile'] as $axis => $value) {
            IngredientProfile::create([
                'ingredient_id' => $id,
                'axis' => $axis,
                'value' => $value,
                'source' => $data['source'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'notes' => $data['notes'] ?? null,
                'suggestable_for_classics' => $data['suggestable_for_classics'] ?? true,
                'scored_at' => $data['scored_at'] ?? now()->toDateString(),
            ]);
        }

        return $this->ingredientProfile($id);
    }

    /**
     * PUT /api/cocktails/{id}/slots/{sort}/meta
     *
     * Upsert slot meta. Required before any constraints can be added on the slot.
     *
     * Body:
     *   {category, tolerance?, exact_ingredient_id?, also_accept_categories?,
     *    proof_min?, proof_max?}
     */
    public function putSlotMeta(Request $request, int $cocktailId, int $sort): JsonResponse
    {
        $cocktail = Cocktail::query()->filterByBar()->where('id', $cocktailId)->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }

        $data = $request->validate([
            'category' => 'required|string|max:64',
            'tolerance' => 'nullable|string|in:exact,style,any',
            'exact_ingredient_id' => 'nullable|integer|exists:ingredients,id',
            'also_accept_categories' => 'nullable|array',
            'also_accept_categories.*' => 'string|max:64',
            'proof_min' => 'nullable|numeric|min:0|max:200',
            'proof_max' => 'nullable|numeric|min:0|max:200',
        ]);

        if (!CategoryAxes::where('category', $data['category'])->exists()) {
            return response()->json(['message' => "Unknown category '{$data['category']}'"], 422);
        }

        SlotMeta::updateOrCreate(
            ['cocktail_id' => $cocktailId, 'sort' => $sort],
            [
                'category' => $data['category'],
                'tolerance' => $data['tolerance'] ?? 'style',
                'exact_ingredient_id' => $data['exact_ingredient_id'] ?? null,
                'also_accept_json' => $data['also_accept_categories'] ?? null,
                'proof_min' => $data['proof_min'] ?? null,
                'proof_max' => $data['proof_max'] ?? null,
            ],
        );

        return response()->json([
            'data' => [
                'cocktail_id' => $cocktailId,
                'sort' => $sort,
                'category' => $data['category'],
                'tolerance' => $data['tolerance'] ?? 'style',
                'exact_ingredient_id' => $data['exact_ingredient_id'] ?? null,
                'also_accept_categories' => $data['also_accept_categories'] ?? [],
                'proof_min' => $data['proof_min'] ?? null,
                'proof_max' => $data['proof_max'] ?? null,
            ],
        ]);
    }

    /**
     * PUT /api/cocktails/{id}/slots/{sort}/constraints/{axis}
     *
     * Upsert a single-axis constraint on a slot. Two shapes:
     *   Band:  {"kind": "band", "lo": 2, "hi": 3, "out_weight": 1.5, "hard": false}
     *   Point: {"kind": "point", "value": 3, "weight": 1.0}
     *
     * Validates that the axis belongs to the slot's category.
     */
    public function putSlotConstraint(Request $request, int $cocktailId, int $sort, string $axis): JsonResponse
    {
        $cocktail = Cocktail::query()->filterByBar()->where('id', $cocktailId)->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }
        $slotMeta = SlotMeta::where('cocktail_id', $cocktailId)->where('sort', $sort)->first();
        if (!$slotMeta) {
            return response()->json(['message' => 'Slot meta not declared — PUT /slots/{sort}/meta first'], 422);
        }

        $axesRow = CategoryAxes::where('category', $slotMeta->category)->first();
        if (!$axesRow || !in_array($axis, $axesRow->axes(), true)) {
            return response()->json([
                'message' => "Axis '{$axis}' is not in category '{$slotMeta->category}'",
                'valid_axes' => $axesRow?->axes() ?? [],
            ], 422);
        }

        $kind = $request->input('kind');
        if ($kind === 'point') {
            $data = $request->validate([
                'kind' => 'required|in:point',
                'value' => 'required|integer|min:0|max:3',
                'weight' => 'nullable|numeric|min:0|max:10',
            ]);
            SlotConstraint::updateOrCreate(
                ['cocktail_id' => $cocktailId, 'sort' => $sort, 'axis' => $axis],
                [
                    'kind' => 'point',
                    'point_value' => $data['value'],
                    'band_lo' => null,
                    'band_hi' => null,
                    'weight' => $data['weight'] ?? 1.0,
                    'out_weight' => 1.0,
                    'hard' => false,
                ],
            );
        } elseif ($kind === 'band') {
            $data = $request->validate([
                'kind' => 'required|in:band',
                'lo' => 'required|integer|min:0|max:3',
                'hi' => 'required|integer|min:0|max:3|gte:lo',
                'out_weight' => 'nullable|numeric|min:0|max:10',
                'hard' => 'nullable|boolean',
            ]);
            SlotConstraint::updateOrCreate(
                ['cocktail_id' => $cocktailId, 'sort' => $sort, 'axis' => $axis],
                [
                    'kind' => 'band',
                    'point_value' => null,
                    'band_lo' => $data['lo'],
                    'band_hi' => $data['hi'],
                    'weight' => 1.0,
                    'out_weight' => $data['out_weight'] ?? 1.0,
                    'hard' => $data['hard'] ?? false,
                ],
            );
        } else {
            return response()->json(['message' => "kind must be 'point' or 'band'"], 422);
        }

        $row = SlotConstraint::where('cocktail_id', $cocktailId)
            ->where('sort', $sort)
            ->where('axis', $axis)
            ->first();

        return response()->json([
            'data' => [
                'cocktail_id' => $cocktailId,
                'sort' => $sort,
                'axis' => $axis,
                'kind' => $row->kind,
                'point_value' => $row->point_value,
                'band_lo' => $row->band_lo,
                'band_hi' => $row->band_hi,
                'weight' => (float) $row->weight,
                'out_weight' => (float) $row->out_weight,
                'hard' => (bool) $row->hard,
            ],
        ]);
    }

    /**
     * DELETE /api/cocktails/{id}/slots/{sort}/constraints/{axis}
     */
    public function deleteSlotConstraint(int $cocktailId, int $sort, string $axis): JsonResponse
    {
        $cocktail = Cocktail::query()->filterByBar()->where('id', $cocktailId)->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }
        $deleted = SlotConstraint::where('cocktail_id', $cocktailId)
            ->where('sort', $sort)
            ->where('axis', $axis)
            ->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    /**
     * GET /api/cocktails/{id}/flavor-slots
     *
     * Which of a cocktail's ingredient slots have flavor meta / constraints
     * declared. The caller already has the cocktail's ingredient list (names,
     * sorts) from /api/cocktails/{id}; this just adds the flavor overlay.
     *
     * → {slots_with_meta: [sort,...], slots_with_constraints: [sort,...]}
     */
    public function cocktailFlavorSlots(int $cocktailId): JsonResponse
    {
        $cocktail = Cocktail::query()->filterByBar()->where('id', $cocktailId)->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }

        $withMeta = SlotMeta::where('cocktail_id', $cocktailId)->pluck('sort')->map(fn ($v) => (int) $v)->all();
        $withConstraints = SlotConstraint::where('cocktail_id', $cocktailId)
            ->distinct()->pluck('sort')->map(fn ($v) => (int) $v)->all();

        return response()->json([
            'data' => [
                'cocktail_id' => $cocktailId,
                'slots_with_meta' => $withMeta,
                'slots_with_constraints' => $withConstraints,
            ],
        ]);
    }

    /**
     * GET /api/cocktails/{id}/flavor-constraints
     *
     * All slot meta + per-axis constraints declared for a cocktail.
     *
     * → {slots: [{sort, category, tolerance, also_accept_categories,
     *             proof_min, proof_max, constraints: [{axis, kind, ...}]}]}
     */
    public function cocktailFlavorConstraints(int $cocktailId): JsonResponse
    {
        $cocktail = Cocktail::query()->filterByBar()->where('id', $cocktailId)->first();
        if (!$cocktail) {
            return response()->json(['message' => 'Cocktail not found'], 404);
        }

        $metas = SlotMeta::where('cocktail_id', $cocktailId)->orderBy('sort')->get();
        $slots = [];
        foreach ($metas as $m) {
            $constraints = SlotConstraint::where('cocktail_id', $cocktailId)
                ->where('sort', $m->sort)->orderBy('axis')->get()
                ->map(fn (SlotConstraint $c) => [
                    'axis' => $c->axis,
                    'kind' => $c->kind,
                    'point_value' => $c->point_value,
                    'band_lo' => $c->band_lo,
                    'band_hi' => $c->band_hi,
                    'weight' => (float) $c->weight,
                    'out_weight' => (float) $c->out_weight,
                    'hard' => (bool) $c->hard,
                ])->all();
            $slots[] = [
                'sort' => $m->sort,
                'category' => $m->category,
                'tolerance' => $m->tolerance,
                'also_accept_categories' => $m->alsoAccept(),
                'proof_min' => $m->proof_min,
                'proof_max' => $m->proof_max,
                'constraints' => $constraints,
            ];
        }

        return response()->json(['data' => ['cocktail_id' => $cocktailId, 'slots' => $slots]]);
    }

    /**
     * GET /api/ingredients/{id}/flavor-uses?top_n=10
     *
     * Recipes (with declared slot constraints) that welcome this bottle.
     *
     * → {ingredient_id, name, category, has_profile, matches: [{cocktail_id,
     *    cocktail_name, sort, penalty, verdict, disqualified, flags}]}
     */
    public function ingredientFlavorUses(int $id): JsonResponse
    {
        $ingredient = Ingredient::query()->filterByBar()->where('id', $id)->first();
        if (!$ingredient) {
            return response()->json(['message' => 'Ingredient not found'], 404);
        }

        $topN = (int) request()->integer('top_n', 10);
        $bottle = $this->service->loadBottle($id);
        if ($bottle === null) {
            return response()->json([
                'data' => [
                    'ingredient_id' => $id,
                    'name' => $ingredient->name,
                    'has_profile' => false,
                    'matches' => [],
                ],
            ]);
        }

        $slots = $this->service->loadAllSlots();
        $engine = new Engine();
        $matches = $engine->usesForBottle($bottle, $slots, $topN);

        // Resolve cocktail names in one query.
        $cocktailIds = array_values(array_unique(array_map(fn ($m) => $m['slot']->cocktailId, $matches)));
        $names = Cocktail::whereIn('id', $cocktailIds)->pluck('name', 'id');

        $payload = array_map(fn ($m) => [
            'cocktail_id' => $m['slot']->cocktailId,
            'cocktail_name' => $names[$m['slot']->cocktailId] ?? ('#' . $m['slot']->cocktailId),
            'sort' => $m['slot']->sort,
            'penalty' => round($m['assessment']->penalty, 2),
            'verdict' => $m['assessment']->verdict(),
            'disqualified' => $m['assessment']->disqualified,
            'flags' => $m['assessment']->flags,
        ], $matches);

        return response()->json([
            'data' => [
                'ingredient_id' => $id,
                'name' => $bottle->name,
                'category' => $bottle->category,
                'has_profile' => true,
                'matches' => $payload,
            ],
        ]);
    }

    /**
     * GET /api/flavor/gaps?threshold=3.0&cocktail_ids[]=...
     *
     * Slots whose best in-stock bottle is a stretch — the shopping list.
     *
     * → {threshold, gaps: [{cocktail_id, cocktail_name, sort, category,
     *    best_bottle_id, best_bottle_name, penalty, reason}]}
     */
    public function gaps(): JsonResponse
    {
        $threshold = (float) request()->input('threshold', 3.0);
        $cocktailIds = request()->input('cocktail_ids');
        $cocktailIds = is_array($cocktailIds) ? array_map('intval', $cocktailIds) : null;

        $slots = $this->service->loadAllSlots();
        if ($cocktailIds !== null) {
            $wanted = array_flip($cocktailIds);
            $slots = array_values(array_filter($slots, fn ($s) => isset($wanted[$s->cocktailId])));
        }
        if (empty($slots)) {
            return response()->json(['data' => ['threshold' => $threshold, 'gaps' => []]]);
        }

        // Load all profiled bottles, mark on-shelf via the bar shelf.
        $bottles = $this->service->loadBottles();
        $shelf = array_flip($this->shelfIngredientIds());
        $bottles = array_map(
            fn (Bottle $b) => new Bottle(
                id: $b->id, name: $b->name, category: $b->category, profile: $b->profile,
                proof: $b->proof, inStock: isset($shelf[$b->id]),
                source: $b->source, confidence: $b->confidence, notes: $b->notes,
                suggestableForClassics: $b->suggestableForClassics,
            ),
            $bottles,
        );

        $engine = new Engine();
        $gaps = $engine->findGaps($bottles, $slots, $threshold);

        $cocktailIdsForNames = array_values(array_unique(array_map(fn ($g) => $g['slot']->cocktailId, $gaps)));
        $names = Cocktail::whereIn('id', $cocktailIdsForNames)->pluck('name', 'id');

        $payload = array_map(fn ($g) => [
            'cocktail_id' => $g['slot']->cocktailId,
            'cocktail_name' => $names[$g['slot']->cocktailId] ?? ('#' . $g['slot']->cocktailId),
            'sort' => $g['slot']->sort,
            'category' => $g['slot']->category,
            'best_bottle_id' => $g['bottle']->id ?? null,
            'best_bottle_name' => $g['bottle']->name ?? null,
            'penalty' => is_finite($g['penalty']) ? round($g['penalty'], 2) : null,
            'reason' => $g['reason'],
        ], $gaps);

        return response()->json(['data' => ['threshold' => $threshold, 'gaps' => $payload]]);
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
