<?php
// Seed minimal data for Slice 1/2/3 smoke test.
$root = '/var/www/cocktails';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Kami\Cocktail\Models\Bar;
use Kami\Cocktail\Models\BarIngredient;
use Kami\Cocktail\Models\BarMembership;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\CocktailIngredient;
use Kami\Cocktail\Models\Glass;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\Enums\UserRoleEnum;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;
use Kami\Cocktail\Models\Flavor\SlotConstraint;
use Kami\Cocktail\Models\Flavor\SlotMeta;

// User + bar + membership
$user = User::where('email', 'smoke@test.local')->first()
    ?: User::factory()->create(['email' => 'smoke@test.local', 'name' => 'Smoke']);
$user->password = bcrypt('password');
$user->save();

$bar = Bar::where('name', 'Smoke Bar')->first()
    ?: Bar::factory()->create(['name' => 'Smoke Bar', 'created_user_id' => $user->id]);

if (!BarMembership::where('bar_id', $bar->id)->where('user_id', $user->id)->exists()) {
    BarMembership::factory()->recycle($user, $bar)
        ->create(['user_role_id' => UserRoleEnum::Admin->value]);
}

$token = $user->createToken('smoke', ['*'])->plainTextToken;
echo "USER_ID={$user->id}  BAR_ID={$bar->id}  TOKEN={$token}\n";

// Gin shelf — 4 bottles with distinct profiles so alternatives have variety.
$gins = [
    ['Plymouth Navy Strength', 57, ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0]],
    ['Ford\'s London Dry Gin',   45, ['juniper' => 2, 'citrus' => 2, 'floral' => 2, 'heat' => 2, 'spice' => 2, 'herbal' => 1, 'fruited' => 0]],
    ['Hendricks-style Gin',     44, ['juniper' => 1, 'citrus' => 2, 'floral' => 3, 'heat' => 1, 'spice' => 1, 'herbal' => 2, 'fruited' => 1]],
    ['Renais-style Gin',        40, ['juniper' => 2, 'citrus' => 3, 'floral' => 3, 'heat' => 1, 'spice' => 1, 'herbal' => 1, 'fruited' => 3]],
];
$ingredients = [];
foreach ($gins as [$name, $abv, $profile]) {
    $ing = Ingredient::where('name', $name)->where('bar_id', $bar->id)->first()
        ?: Ingredient::factory()->for($bar)->create(['name' => $name, 'strength' => $abv, 'created_user_id' => $user->id]);
    $ingredients[$name] = $ing;

    IngredientCategory::updateOrCreate(['ingredient_id' => $ing->id], ['category' => 'gin']);
    IngredientProfile::where('ingredient_id', $ing->id)->delete();
    foreach ($profile as $axis => $value) {
        IngredientProfile::create([
            'ingredient_id' => $ing->id, 'axis' => $axis, 'value' => $value,
            'source' => 'tgii', 'confidence' => 'high', 'suggestable_for_classics' => true,
            'scored_at' => '2026-05-26', 'notes' => null,
        ]);
    }

    if (!BarIngredient::where('bar_id', $bar->id)->where('ingredient_id', $ing->id)->exists()) {
        BarIngredient::factory()->for($bar)->for($ing)->create();
    }
}

// One cocktail (Negroni-shape) with a gin slot at sort=1, hard floral cap.
$campari = Ingredient::where('name', 'Campari')->where('bar_id', $bar->id)->first()
    ?: Ingredient::factory()->for($bar)->create(['name' => 'Campari', 'strength' => 25, 'created_user_id' => $user->id]);
$vermouth = Ingredient::where('name', 'Sweet Vermouth')->where('bar_id', $bar->id)->first()
    ?: Ingredient::factory()->for($bar)->create(['name' => 'Sweet Vermouth', 'strength' => 16, 'created_user_id' => $user->id]);

$cocktail = Cocktail::where('name', 'Sandbox Negroni')->where('bar_id', $bar->id)->first();
if (!$cocktail) {
    $cocktail = Cocktail::factory()->for($bar)->create([
        'name' => 'Sandbox Negroni',
        'description' => 'Demo cocktail seeded for Slice 3 — exercises the FlavorAlternatives panel.',
        'instructions' => 'Stir all ingredients with ice; strain into a rocks glass over a large cube; garnish with orange peel.',
        'garnish' => 'Orange peel',
    ]);
    foreach ([
        ['sort' => 1, 'ingredient_id' => $ingredients['Plymouth Navy Strength']->id, 'amount' => 1.0, 'units' => 'oz'],
        ['sort' => 2, 'ingredient_id' => $campari->id, 'amount' => 1.0, 'units' => 'oz'],
        ['sort' => 3, 'ingredient_id' => $vermouth->id, 'amount' => 1.0, 'units' => 'oz'],
    ] as $row) {
        $ci = new CocktailIngredient();
        $ci->cocktail_id = $cocktail->id;
        $ci->sort = $row['sort'];
        $ci->ingredient_id = $row['ingredient_id'];
        $ci->amount = $row['amount'];
        $ci->units = $row['units'];
        $ci->save();
    }
}

// Slot meta + constraints on the gin slot
SlotMeta::updateOrCreate(
    ['cocktail_id' => $cocktail->id, 'sort' => 1],
    ['category' => 'gin', 'tolerance' => 'style'],
);
foreach ([
    ['axis' => 'juniper', 'kind' => 'band', 'band_lo' => 2, 'band_hi' => 3, 'weight' => 1.0, 'out_weight' => 1.5, 'hard' => false],
    ['axis' => 'floral',  'kind' => 'band', 'band_lo' => 0, 'band_hi' => 2, 'weight' => 1.0, 'out_weight' => 2.0, 'hard' => true],
    ['axis' => 'fruited', 'kind' => 'band', 'band_lo' => 0, 'band_hi' => 1, 'weight' => 1.0, 'out_weight' => 1.5, 'hard' => false],
] as $c) {
    SlotConstraint::updateOrCreate(
        ['cocktail_id' => $cocktail->id, 'sort' => 1, 'axis' => $c['axis']],
        array_merge($c, ['sort' => 1, 'point_value' => null]),
    );
}
echo "COCKTAIL_ID={$cocktail->id}\n";
echo "SEEDED\n";
