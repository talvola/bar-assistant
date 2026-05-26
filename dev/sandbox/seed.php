<?php
// Seed minimal data for Slice 1 smoke test.
$root = '/var/www/cocktails';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Kami\Cocktail\Models\Bar;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\BarMembership;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\Enums\UserRoleEnum;
use Kami\Cocktail\Models\Flavor\CategoryAxes;
use Kami\Cocktail\Models\Flavor\IngredientCategory;
use Kami\Cocktail\Models\Flavor\IngredientProfile;

// User + bar + membership via factories (models have no $fillable, so direct
// mass-assignment via ::create() doesn't work — use factories).
$user = User::query()->where('email', 'smoke@test.local')->first();
if (!$user) {
    $user = User::factory()->create(['email' => 'smoke@test.local', 'name' => 'Smoke']);
}

$bar = Bar::query()->where('name', 'Smoke Bar')->first();
if (!$bar) {
    $bar = Bar::factory()->create(['name' => 'Smoke Bar', 'created_user_id' => $user->id]);
}

$membership = BarMembership::query()
    ->where('bar_id', $bar->id)->where('user_id', $user->id)->first();
if (!$membership) {
    $membership = BarMembership::factory()
        ->recycle($user, $bar)
        ->create(['user_role_id' => UserRoleEnum::Admin->value]);
}

// API token (always create a fresh one for visibility)
$token = $user->createToken('smoke', ['*'])->plainTextToken;
echo "USER_ID={$user->id}\n";
echo "BAR_ID={$bar->id}\n";
echo "TOKEN={$token}\n";

// One gin (Plymouth Navy)
$plymouth = Ingredient::query()
    ->where('name', 'Plymouth Navy Strength')->where('bar_id', $bar->id)->first();
if (!$plymouth) {
    $plymouth = Ingredient::factory()
        ->for($bar)
        ->create(['name' => 'Plymouth Navy Strength', 'strength' => 57, 'created_user_id' => $user->id]);
}
echo "INGREDIENT_ID={$plymouth->id}\n";

// Flavor data (these have fillable properties)
CategoryAxes::updateOrCreate(
    ['category' => 'gin'],
    ['axes_json' => ['juniper', 'citrus', 'floral', 'heat', 'spice', 'herbal', 'fruited']],
);
IngredientCategory::updateOrCreate(
    ['ingredient_id' => $plymouth->id],
    ['category' => 'gin'],
);
$profile = ['juniper' => 3, 'citrus' => 2, 'floral' => 0, 'heat' => 3, 'spice' => 2, 'herbal' => 0, 'fruited' => 0];
foreach ($profile as $axis => $value) {
    IngredientProfile::updateOrCreate(
        ['ingredient_id' => $plymouth->id, 'axis' => $axis],
        ['value' => $value, 'source' => 'tgii', 'confidence' => 'high', 'scored_at' => '2026-05-26', 'notes' => 'Seeded for Slice 1 smoke test.'],
    );
}
echo "SEEDED\n";
