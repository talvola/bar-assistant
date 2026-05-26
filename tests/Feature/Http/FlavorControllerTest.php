<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\CocktailIngredient;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\Flavor\CategoryAxes;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;
use Kami\Cocktail\Models\Flavor\SlotConstraint;
use Kami\Cocktail\Models\Flavor\SlotMeta;

/**
 * Slice 1 read-only endpoints. Editing endpoints (PUT/DELETE) covered in
 * later slices.
 */
class FlavorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_categories_returns_axes(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // CategoryAxes are now pre-seeded by the migration (11 categories);
        // gin + amaro are both present already.
        $response = $this->getJson('/api/flavor/categories');

        $response->assertOk();
        $response->assertJsonFragment(['category' => 'gin']);
        $response->assertJsonFragment(['category' => 'amaro']);
        $response->assertJsonFragment(['category' => 'rum']);
    }

    public function test_ingredient_profile_returns_profile_with_provenance(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        $gin = Ingredient::factory()->for($membership->bar)->create(['name' => 'Plymouth Navy Strength']);

        IngredientCategory::create(['ingredient_id' => $gin->id, 'category' => 'gin']);
        foreach ([
            ['axis' => 'juniper', 'value' => 3],
            ['axis' => 'citrus',  'value' => 2],
            ['axis' => 'heat',    'value' => 3],
            ['axis' => 'floral',  'value' => 0],
        ] as $p) {
            IngredientProfile::create([
                'ingredient_id' => $gin->id,
                'axis' => $p['axis'],
                'value' => $p['value'],
                'source' => 'tgii',
                'confidence' => 'high',
                'notes' => null,
                'suggestable_for_classics' => true,
                'scored_at' => '2026-05-23',
            ]);
        }

        $response = $this->getJson("/api/ingredients/{$gin->id}/flavor-profile");

        $response->assertOk();
        $response->assertJsonPath('data.ingredient_id', $gin->id);
        $response->assertJsonPath('data.category', 'gin');
        $response->assertJsonPath('data.profile.juniper', 3);
        $response->assertJsonPath('data.profile.heat', 3);
        $response->assertJsonPath('data.profile.floral', 0);
        $response->assertJsonPath('data.source', 'tgii');
        $response->assertJsonPath('data.confidence', 'high');
        $response->assertJsonPath('data.suggestable_for_classics', true);
    }

    public function test_ingredient_profile_returns_404_when_no_profile(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        $ing = Ingredient::factory()->for($membership->bar)->create();

        $response = $this->getJson("/api/ingredients/{$ing->id}/flavor-profile");
        $response->assertNotFound();
    }

    public function test_alternatives_for_slot_ranks_in_pattern_first(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // CategoryAxes (gin) already seeded by migration.

        // Three gins: in-pattern, slight stray (low juniper), hard-disqualified (high floral).
        $plymouth = $this->seedGin($membership->bar->id, 'Plymouth Navy',
            ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0]);
        $jamesGin = $this->seedGin($membership->bar->id, 'James Gin California',
            ['juniper' => 1, 'citrus' => 1, 'floral' => 0, 'heat' => 1, 'spice' => 1, 'herbal' => 3, 'fruited' => 1]);
        $renais = $this->seedGin($membership->bar->id, 'Renais Gin',
            ['juniper' => 2, 'citrus' => 3, 'floral' => 3, 'heat' => 1, 'spice' => 1, 'herbal' => 1, 'fruited' => 3]);

        // Put all 3 on shelf.
        foreach ([$plymouth, $jamesGin, $renais] as $ing) {
            \Kami\Cocktail\Models\BarIngredient::factory()
                ->for($membership->bar)
                ->for($ing)
                ->create();
        }

        // A cocktail with one gin slot — Negroni-shape (no need to populate the other ingredients).
        $cocktail = Cocktail::factory()->for($membership->bar)->create(['name' => 'Negroni-shape']);
        // Slot meta: gin, with floral hard cap excluding floral=3.
        SlotMeta::create([
            'cocktail_id' => $cocktail->id,
            'sort' => 1,
            'category' => 'gin',
            'tolerance' => 'style',
        ]);
        SlotConstraint::create([
            'cocktail_id' => $cocktail->id,
            'sort' => 1,
            'axis' => 'juniper',
            'kind' => 'band',
            'band_lo' => 2,
            'band_hi' => 3,
            'out_weight' => 1.5,
            'hard' => false,
        ]);
        SlotConstraint::create([
            'cocktail_id' => $cocktail->id,
            'sort' => 1,
            'axis' => 'floral',
            'kind' => 'band',
            'band_lo' => 0,
            'band_hi' => 2,
            'out_weight' => 2.0,
            'hard' => true,
        ]);

        $response = $this->getJson("/api/cocktails/{$cocktail->id}/slots/1/alternatives?include_strays=true&top_n=10");

        $response->assertOk();
        $data = $response->json('data.alternatives');

        // First should be Plymouth (in-pattern).
        $this->assertSame('Plymouth Navy', $data[0]['bottle']['name']);
        $this->assertSame(0.0, (float) $data[0]['penalty']);
        $this->assertFalse($data[0]['disqualified']);

        // Renais should appear with disqualified=true (floral=3 above hard band 0-2).
        $renaisRow = collect($data)->firstWhere('bottle.name', 'Renais Gin');
        $this->assertNotNull($renaisRow);
        $this->assertTrue($renaisRow['disqualified']);

        // James (juniper=1 below band 2-3) is slight stray but not disqualified.
        $jamesRow = collect($data)->firstWhere('bottle.name', 'James Gin California');
        $this->assertNotNull($jamesRow);
        $this->assertFalse($jamesRow['disqualified']);
        $this->assertGreaterThan(0, (float) $jamesRow['penalty']);
    }

    // ---- Slice 2: PUT/DELETE endpoints ------------------------------------

    public function test_put_ingredient_profile_creates_rows(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin axes seeded by migration.
        $gin = Ingredient::factory()->for($membership->bar)->create(['name' => 'Plymouth Navy']);

        $response = $this->putJson("/api/ingredients/{$gin->id}/flavor-profile", [
            'category' => 'gin',
            'profile' => ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0],
            'source' => 'tgii',
            'confidence' => 'high',
            'notes' => 'Test',
            'scored_at' => '2026-05-26',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.profile.juniper', 3);
        $response->assertJsonPath('data.category', 'gin');
        $response->assertJsonPath('data.source', 'tgii');
        $this->assertSame(7, IngredientProfile::where('ingredient_id', $gin->id)->count());
        $this->assertSame('gin', IngredientCategory::where('ingredient_id', $gin->id)->value('category'));
    }

    public function test_put_ingredient_profile_rejects_unknown_axis(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin axes seeded by migration.
        $gin = Ingredient::factory()->for($membership->bar)->create();

        $response = $this->putJson("/api/ingredients/{$gin->id}/flavor-profile", [
            'category' => 'gin',
            'profile' => ['juniper' => 3, 'WRONG_AXIS' => 2],
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('unknown_axes.0', 'WRONG_AXIS');
    }

    public function test_put_ingredient_profile_replaces_existing_rows(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin + aquavit axes seeded by migration.
        $ing = Ingredient::factory()->for($membership->bar)->create();

        // Initial profile in gin
        $this->putJson("/api/ingredients/{$ing->id}/flavor-profile", [
            'category' => 'gin',
            'profile' => ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0],
        ])->assertOk();
        $this->assertSame(7, IngredientProfile::where('ingredient_id', $ing->id)->count());

        // Re-categorize as aquavit (6 axes) — `fruited` row from gin should be gone.
        $this->putJson("/api/ingredients/{$ing->id}/flavor-profile", [
            'category' => 'aquavit',
            'profile' => ['juniper' => 2, 'citrus' => 1, 'floral' => 0, 'heat' => 1, 'spice' => 3, 'herbal' => 2],
        ])->assertOk();
        $this->assertSame(6, IngredientProfile::where('ingredient_id', $ing->id)->count());
        $this->assertSame('aquavit', IngredientCategory::where('ingredient_id', $ing->id)->value('category'));
        $this->assertNull(IngredientProfile::where('ingredient_id', $ing->id)->where('axis', 'fruited')->first());
    }

    public function test_put_slot_meta_and_constraints_roundtrip(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin axes seeded by migration.
        $cocktail = Cocktail::factory()->for($membership->bar)->create();

        $this->putJson("/api/cocktails/{$cocktail->id}/slots/2/meta", [
            'category' => 'gin',
            'tolerance' => 'style',
            'also_accept_categories' => ['aquavit'],
        ])->assertOk();
        $this->assertSame('gin', SlotMeta::where('cocktail_id', $cocktail->id)->where('sort', 2)->value('category'));

        // Band constraint
        $this->putJson("/api/cocktails/{$cocktail->id}/slots/2/constraints/juniper", [
            'kind' => 'band',
            'lo' => 2,
            'hi' => 3,
            'out_weight' => 1.5,
            'hard' => false,
        ])->assertOk();
        $row = SlotConstraint::where('cocktail_id', $cocktail->id)->where('sort', 2)->where('axis', 'juniper')->first();
        $this->assertSame('band', $row->kind);
        $this->assertSame(2, $row->band_lo);
        $this->assertSame(3, $row->band_hi);

        // Hard band overwrites the previous
        $this->putJson("/api/cocktails/{$cocktail->id}/slots/2/constraints/juniper", [
            'kind' => 'band', 'lo' => 1, 'hi' => 3, 'hard' => true, 'out_weight' => 2.0,
        ])->assertOk();
        $row->refresh();
        $this->assertSame(1, $row->band_lo);
        $this->assertTrue((bool) $row->hard);

        // Point constraint on a different axis
        $this->putJson("/api/cocktails/{$cocktail->id}/slots/2/constraints/citrus", [
            'kind' => 'point', 'value' => 2, 'weight' => 1.0,
        ])->assertOk();
        $this->assertSame(2, SlotConstraint::where('cocktail_id', $cocktail->id)->where('sort', 2)->count());

        // Delete one constraint
        $this->deleteJson("/api/cocktails/{$cocktail->id}/slots/2/constraints/juniper")->assertOk();
        $this->assertSame(1, SlotConstraint::where('cocktail_id', $cocktail->id)->where('sort', 2)->count());
    }

    public function test_put_slot_constraint_rejects_axis_not_in_category(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin (seeded by migration) has axes juniper/citrus/floral/heat/spice/herbal/fruited — `bitter` is NOT in the list.
        $cocktail = Cocktail::factory()->for($membership->bar)->create();

        // Meta declares the slot as gin
        $this->putJson("/api/cocktails/{$cocktail->id}/slots/1/meta", ['category' => 'gin'])->assertOk();

        // `bitter` isn't in gin's axes
        $response = $this->putJson("/api/cocktails/{$cocktail->id}/slots/1/constraints/bitter", [
            'kind' => 'band', 'lo' => 2, 'hi' => 3,
        ]);
        $response->assertStatus(422);
    }

    public function test_put_slot_constraint_requires_existing_meta(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $membership->bar_id);

        // gin already seeded
        $cocktail = Cocktail::factory()->for($membership->bar)->create();

        // No SlotMeta yet — constraint PUT should fail
        $this->putJson("/api/cocktails/{$cocktail->id}/slots/1/constraints/juniper", [
            'kind' => 'band', 'lo' => 2, 'hi' => 3,
        ])->assertStatus(422);
    }

    /**
     * Seed a gin with a 7-axis profile and the ingredient_category row.
     * @param array<string, int> $profile
     */
    private function seedGin(int $barId, string $name, array $profile): Ingredient
    {
        $ing = Ingredient::factory()->create(['name' => $name, 'bar_id' => $barId, 'strength' => 45]);
        IngredientCategory::create(['ingredient_id' => $ing->id, 'category' => 'gin']);
        foreach ($profile as $axis => $value) {
            IngredientProfile::create([
                'ingredient_id' => $ing->id,
                'axis' => $axis,
                'value' => $value,
                'source' => 'tgii',
                'confidence' => 'high',
                'suggestable_for_classics' => true,
                'scored_at' => '2026-05-26',
            ]);
        }
        return $ing;
    }
}
