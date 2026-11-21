<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a manager can create and edit an inventory product', function () {
    $productData = [
        'name' => 'Chicken Rice',
        'category' => 'Meals',
        'cost' => 50,
        'sellingPrice' => 85,
        'img' => '',
        'addOns' => [['name' => 'Extra rice', 'price' => 15]],
        'ingredients' => [
            ['name' => 'Rice', 'stock' => 10],
            ['name' => 'Chicken', 'stock' => 2],
        ],
    ];

    $createdProduct = $this->postJson(route('products.store'), $productData)
        ->assertCreated()
        ->assertJsonPath('name', 'Chicken Rice')
        ->assertJsonPath('stock', 2)
        ->json();

    $updatedProductData = [
        ...$productData,
        'name' => 'Chicken Rice Special',
        'sellingPrice' => 99,
        'ingredients' => [
            ['name' => 'Rice', 'stock' => 15],
            ['name' => 'Chicken', 'stock' => 3],
        ],
    ];

    $this->putJson(route('products.update', $createdProduct['id']), $updatedProductData)
        ->assertSuccessful()
        ->assertJsonPath('name', 'Chicken Rice Special')
        ->assertJsonPath('sellingPrice', 99)
        ->assertJsonPath('stock', 2);

    $this->assertDatabaseHas('products', [
        'id' => $createdProduct['id'],
        'name' => 'Chicken Rice Special',
        'selling_price' => 99,
        'stock_quantity' => 2,
    ]);

    $this->assertDatabaseHas('ingredients', [
        'name' => 'Rice',
        'stock_quantity' => 10,
    ]);
});

test('products share ingredients and central restocking updates every linked product stock', function () {
    $tapsilog = $this->postJson(route('products.store'), [
        'name' => 'Tapsilog',
        'sellingPrice' => 100,
        'ingredients' => [
            ['name' => 'Egg', 'stock' => 100],
            ['name' => 'Beef', 'stock' => 100],
        ],
    ])->assertCreated()->json();

    $hatsilog = $this->postJson(route('products.store'), [
        'name' => 'Hatsilog',
        'sellingPrice' => 90,
        'ingredients' => [
            ['name' => 'Egg', 'stock' => 5],
            ['name' => 'Ham', 'stock' => 80],
        ],
    ])->assertCreated()->json();

    $sharedEgg = collect($tapsilog['ingredients'])->firstWhere('name', 'Egg');
    $hatsilogEgg = collect($hatsilog['ingredients'])->firstWhere('name', 'Egg');

    expect($hatsilogEgg['id'])->toBe($sharedEgg['id'])
        ->and($hatsilogEgg['stock'])->toBe(100)
        ->and($hatsilog['stock'])->toBe(80);

    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager)
        ->getJson(route('manager.api.ingredients.index'))
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $sharedEgg['id'],
            'name' => 'Egg',
            'stock' => 100,
        ]);

    $this->actingAs($manager)
        ->putJson(route('manager.api.ingredients.update', $sharedEgg['id']), ['stock' => 75])
        ->assertSuccessful()
        ->assertJsonPath('stock', 75);

    $catalog = collect($this->getJson(route('products.index'))->json());
    expect($catalog->firstWhere('id', $tapsilog['id'])['stock'])->toBe(75)
        ->and($catalog->firstWhere('id', $hatsilog['id'])['stock'])->toBe(75);

    $this->assertDatabaseCount('ingredients', 3);
});
