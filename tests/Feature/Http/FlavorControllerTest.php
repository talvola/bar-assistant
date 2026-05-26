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

        CategoryAxes::create([
            'category' => 'gin',
            'axes_json' => ['juniper', 'citrus', 'floral', 'heat', 'spice', 'herbal', 'fruited'],
        ]);
        CategoryAxes::create([
            'category' => 'amaro',
            'axes_json' => ['bitter', 'sweet', 'citrus', 'herbal', 'dark', 'mint', 'root'],
        ]);

        $response = $this->getJson('/api/flavor/categories');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['category' => 'gin']);
        $response->assertJsonFragment(['category' => 'amaro']);
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

        CategoryAxes::create([
            'category' => 'gin',
            'axes_json' => ['juniper', 'citrus', 'floral', 'heat', 'spice', 'herbal', 'fruited'],
        ]);

        // Three gins: in-pattern, slight stray (low juniper), hard-disqualified (high floral).
        $plymouth = $this->seedGin($membership->bar->id, 'Plymouth Navy',
            ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0]);
        $jamesGin = $this->seedGin($membership->bar->id, 'James Gin California',
            ['juniper' => 1, 'citrus' => 1, 'floral' => 0, 'heat' => 1, 'spice' => 1, 'herbal' => 3, 'fruited' => 1]);
        $renais = $this->seedGin($membership->bar->id, 'Renais Gin',
            ['juniper' => 2, 'citrus' => 3, 'floral' => 3, 'heat' => 1, 'spice' => 1, 'herbal' => 1, 'fruited' => 3]);

        // Put all 3 on shelf.
        foreach ([$plymouth, $jamesGin, $renais] as $ing) {
            \Kami\Cocktail\Models\BarIngredient::create([
                'bar_id' => $membership->bar->id,
                'ingredient_id' => $ing->id,
            ]);
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
