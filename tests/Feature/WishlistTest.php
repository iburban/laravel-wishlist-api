<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_can_be_added_to_the_wishlist(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/wishlist', ['product_id' => $product->id]);

        $response->assertCreated()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonMissingPath('data.user_id');

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_the_wishlist_returns_only_the_callers_items(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = Product::factory()->create();
        $theirs = Product::factory()->create();

        Wishlist::factory()->create(['user_id' => $me->id, 'product_id' => $mine->id]);
        Wishlist::factory()->create(['user_id' => $other->id, 'product_id' => $theirs->id]);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/wishlist');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $mine->id);

        // The other user's item is absent from my list.
        $this->assertNotContains(
            $theirs->id,
            collect($response->json('data'))->pluck('product.id')->all(),
        );
    }

    public function test_listing_the_wishlist_eager_loads_products_without_n_plus_1(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(3)->create();
        foreach ($products as $product) {
            Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        }
        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/wishlist')->assertOk()->assertJsonCount(3, 'data');

        // Eager load => exactly one query against products, regardless of item count.
        $productQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from "products"'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $productQueries, 'Expected products to be eager-loaded in a single query.');
    }

    public function test_a_product_can_be_removed_from_the_wishlist(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $item = Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/wishlist/{$product->id}");

        $response->assertOk()
            ->assertExactJson(['message' => 'Product removed from wishlist.']);
        $this->assertModelMissing($item);
    }

    public function test_adding_the_same_product_twice_returns_409(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/wishlist', ['product_id' => $product->id]);

        $response->assertStatus(409)
            ->assertExactJson(['message' => 'Product is already in your wishlist.']);

        // Still exactly one row: the unique index held.
        $this->assertSame(1, Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->count());
    }

    public function test_removing_another_users_item_returns_404_without_leaking_existence(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $theirItem = Wishlist::factory()->create(['user_id' => $other->id, 'product_id' => $product->id]);

        Sanctum::actingAs($me);
        $response = $this->deleteJson("/api/wishlist/{$product->id}");

        $response->assertStatus(404);
        // The other user's row is untouched.
        $this->assertModelExists($theirItem);
    }

    public function test_removing_a_product_not_on_my_list_returns_404(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        // Same 404 as the foreign-item case above => no way to tell the two apart.
        $this->deleteJson("/api/wishlist/{$product->id}")->assertStatus(404);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAddProvider')]
    public function test_adding_with_invalid_input_returns_422(array $payload): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/wishlist', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidAddProvider(): array
    {
        return [
            'missing product_id' => [[]],
            'non-integer product_id' => [['product_id' => 'abc']],
            'non-existent product_id' => [['product_id' => 999999]],
        ];
    }
}
