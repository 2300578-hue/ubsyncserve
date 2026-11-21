<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InventoryIngredient extends Model
{
    protected $table = 'ingredients';

    protected $fillable = [
        'name',
        'normalized_name',
        'stock_quantity',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'integer',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ingredient_product', 'ingredient_id', 'product_id')
            ->withTimestamps();
    }
}
