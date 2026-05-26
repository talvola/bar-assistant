<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\Flavor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kami\Cocktail\Models\Ingredient;

/**
 * One row per (ingredient, axis). Profiles are bar-agnostic — the ingredient
 * itself is bar-scoped via ingredients.bar_id.
 *
 * @property int $ingredient_id
 * @property string $axis
 * @property int $value
 * @property ?string $source
 * @property ?string $confidence
 * @property ?string $notes
 * @property bool $suggestable_for_classics
 * @property ?\Illuminate\Support\Carbon $scored_at
 */
class IngredientProfile extends Model
{
    protected $table = 'flavor_ingredient_profiles';
    public $incrementing = false;

    /**
     * Composite primary key (ingredient_id, axis). Eloquent doesn't natively
     * model composite keys; queries should filter on both fields explicitly.
     */
    protected $primaryKey = null;

    protected $fillable = [
        'ingredient_id',
        'axis',
        'value',
        'source',
        'confidence',
        'notes',
        'suggestable_for_classics',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'suggestable_for_classics' => 'boolean',
            'scored_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
