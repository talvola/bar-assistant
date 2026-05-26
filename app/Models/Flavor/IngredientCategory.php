<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\Flavor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kami\Cocktail\Models\Ingredient;

/**
 * Maps an ingredient to its flavor category (gin/aquavit/amaro/...).
 * One row per ingredient — replaces the materialized_path-inference + cross-
 * path special cases the MCP sidecar carries.
 *
 * @property int $ingredient_id
 * @property string $category
 */
class IngredientCategory extends Model
{
    protected $table = 'flavor_ingredient_categories';
    protected $primaryKey = 'ingredient_id';
    public $incrementing = false;

    protected $fillable = ['ingredient_id', 'category'];

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
