<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('products')->select('id', 'ingredients')->orderBy('id')->get() as $product) {
                $legacyIngredients = json_decode($product->ingredients ?? '[]', true);
                if (! is_array($legacyIngredients)) {
                    continue;
                }

                foreach ($legacyIngredients as $legacyIngredient) {
                    if (
                        ! is_array($legacyIngredient)
                        || ! is_string($legacyIngredient['name'] ?? null)
                        || ! is_numeric($legacyIngredient['stock'] ?? null)
                    ) {
                        continue;
                    }

                    $name = trim($legacyIngredient['name']);
                    $normalizedName = Str::lower($name);
                    if ($name === '') {
                        continue;
                    }

                    $stock = max(0, (int) $legacyIngredient['stock']);
                    $ingredientId = DB::table('ingredients')
                        ->where('normalized_name', $normalizedName)
                        ->value('id');

                    if ($ingredientId === null) {
                        $now = now();
                        $ingredientId = DB::table('ingredients')->insertGetId([
                            'name' => $name,
                            'normalized_name' => $normalizedName,
                            'stock_quantity' => $stock,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $currentStock = (int) DB::table('ingredients')
                            ->where('id', $ingredientId)
                            ->value('stock_quantity');

                        DB::table('ingredients')
                            ->where('id', $ingredientId)
                            ->update([
                                'stock_quantity' => max($currentStock, $stock),
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('ingredient_product')->insertOrIgnore([
                        'ingredient_id' => $ingredientId,
                        'product_id' => $product->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach (DB::table('products')->select('id')->orderBy('id')->get() as $product) {
                $stockQuantity = DB::table('ingredient_product')
                    ->join('ingredients', 'ingredients.id', '=', 'ingredient_product.ingredient_id')
                    ->where('ingredient_product.product_id', $product->id)
                    ->min('ingredients.stock_quantity');

                if ($stockQuantity !== null) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['stock_quantity' => $stockQuantity]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('products')->select('id')->orderBy('id')->get() as $product) {
                $ingredients = DB::table('ingredient_product')
                    ->join('ingredients', 'ingredients.id', '=', 'ingredient_product.ingredient_id')
                    ->where('ingredient_product.product_id', $product->id)
                    ->orderBy('ingredients.id')
                    ->get(['ingredients.name', 'ingredients.stock_quantity'])
                    ->map(static fn (object $ingredient): array => [
                        'name' => $ingredient->name,
                        'stock' => (int) $ingredient->stock_quantity,
                    ])
                    ->all();

                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['ingredients' => json_encode($ingredients, JSON_THROW_ON_ERROR)]);
            }
        });
    }
};
