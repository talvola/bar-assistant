<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\Flavor;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $category
 * @property array<int, string> $axes_json
 */
class CategoryAxes extends Model
{
    protected $table = 'flavor_category_axes';
    protected $primaryKey = 'category';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['category', 'axes_json'];

    protected function casts(): array
    {
        return [
            'axes_json' => 'array',
        ];
    }

    /** @return list<string> */
    public function axes(): array
    {
        return $this->axes_json ?? [];
    }
}
