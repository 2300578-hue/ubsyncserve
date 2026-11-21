<?php

namespace App\Http\Controllers;

use App\Models\InventoryIngredient;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryIngredientController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            InventoryIngredient::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (InventoryIngredient $ingredient): array => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'stock' => $ingredient->stock_quantity,
                ])
        );
    }

    public function update(Request $request, InventoryIngredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        $ingredient = DB::transaction(function () use ($ingredient, $validated): InventoryIngredient {
            $productIds = $ingredient->products()
                ->orderBy('products.id')
                ->pluck('products.id')
                ->all();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('ingredients')
                ->get();
            $ingredient = InventoryIngredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $ingredient->update(['stock_quantity' => $validated['stock']]);
            $products->each(static fn (Product $product) => $product->refreshStockQuantity());

            return $ingredient;
        });

        return response()->json([
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'stock' => $ingredient->stock_quantity,
        ]);
    }

    public function destroy(InventoryIngredient $ingredient): JsonResponse
    {
        DB::transaction(function () use ($ingredient): void {
            $productIds = $ingredient->products()
                ->orderBy('products.id')
                ->pluck('products.id')
                ->all();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $ingredient = InventoryIngredient::query()
                ->lockForUpdate()
                ->findOrFail($ingredient->id);

            $ingredient->products()->detach();
            $ingredient->delete();

            $products->each(static function (Product $product): void {
                $product->unsetRelation('ingredients');
                $product->refreshStockQuantity();
            });
        });

        return response()->json(['success' => true]);
    }
}
