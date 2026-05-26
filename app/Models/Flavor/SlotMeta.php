<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\Flavor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Ingredient;

/**
 * Per-slot meta: which category fills this recipe ingredient slot, with
 * tolerance and optional cross-category acceptance. Composite PK on
 * (cocktail_id, sort).
 *
 * @property int $cocktail_id
 * @property int $sort
 * @property string $category
 * @property string $tolerance
 * @property ?int $exact_ingredient_id
 * @property ?array<int, string> $also_accept_json
 * @property ?float $proof_min
 * @property ?float $proof_max
 */
class SlotMeta extends Model
{
    protected $table = 'flavor_slot_metas';
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'cocktail_id',
        'sort',
        'category',
        'tolerance',
        'exact_ingredient_id',
        'also_accept_json',
        'proof_min',
        'proof_max',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'also_accept_json' => 'array',
            'proof_min' => 'float',
            'proof_max' => 'float',
        ];
    }

    /** @return list<string> */
    public function alsoAccept(): array
    {
        return $this->also_accept_json ?? [];
    }

    /**
     * @return BelongsTo<Cocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function exactIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'exact_ingredient_id');
    }

    /**
     * @return HasMany<SlotConstraint, $this>
     */
    public function constraints(): HasMany
    {
        return $this->hasMany(SlotConstraint::class, 'cocktail_id', 'cocktail_id')
            ->where('sort', $this->sort);
    }
}
