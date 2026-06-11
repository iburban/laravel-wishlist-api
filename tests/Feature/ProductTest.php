<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_listed_without_authentication(): void
    {
        Product::factory()->count(3)->create();

        // No token: the catalog is public and must return 200, not 401.
        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'description', 'price', 'created_at'],
                ],
            ]);
    }

    public function test_product_price_is_serialised_as_a_decimal_string(): void
    {
        Product::factory()->create(['price' => 19.99]);

        $response = $this->getJson('/api/products');

        $response->assertOk();
        $price = $response->json('data.0.price');
        $this->assertIsString($price);
        $this->assertSame('19.99', $price);
    }

    public function test_products_endpoint_returns_the_product_data(): void
    {
        $product = Product::factory()->create([
            'name' => 'Test Widget',
            'description' => 'A handy widget.',
            'price' => 12.50,
        ]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $product->id,
                'name' => 'Test Widget',
                'description' => 'A handy widget.',
                'price' => '12.50',
            ]);
    }
}
