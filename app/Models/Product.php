<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category',
        'cost_price',
        'selling_price',
        'image',
        'stock_quantity',
        'add_ons',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'add_ons' => 'array',
        ];
    }

    public function toCatalogArray(): array
    {
        $ingredients = $this->relationLoaded('ingredients')
            ? $this->getRelation('ingredients')
            : $this->ingredients()->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'cat' => $this->category,
            'cost' => (float) $this->cost_price,
            'sellingPrice' => (float) $this->selling_price,
            'price' => (float) $this->selling_price,
            'img' => $this->image,
            'stock' => $ingredients->isEmpty()
                ? $this->stock_quantity
                : (int) $ingredients->min('stock_quantity'),
            'addOns' => $this->add_ons ?? [],
            'ingredients' => $ingredients->map(static fn (InventoryIngredient $ingredient): array => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'stock' => $ingredient->stock_quantity,
            ])->values()->all(),
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(InventoryIngredient::class, 'ingredient_product', 'product_id', 'ingredient_id')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refreshStockQuantity(): void
    {
        $ingredients = $this->relationLoaded('ingredients')
            ? $this->getRelation('ingredients')
            : $this->ingredients()->get();
        $stockQuantity = $ingredients->isEmpty()
            ? 0
            : $ingredients->min('stock_quantity');

        $this->updateQuietly(['stock_quantity' => (int) $stockQuantity]);
    }
}
