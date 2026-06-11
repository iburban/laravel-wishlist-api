<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_body_is_generic_and_hides_the_model_class(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        // A product that exists but is not on the caller's wishlist => scoped firstOrFail() => 404.
        $response = $this->deleteJson("/api/wishlist/{$product->id}");

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);

        // The body must not leak the model class or the framework's "No query results" detail,
        // otherwise existence of (another user's) data could be inferred.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Wishlist', $body);
        $this->assertStringNotContainsString('App\\Models', $body);
        $this->assertStringNotContainsString('No query results', $body);
    }

    public function test_unknown_api_route_returns_the_same_generic_404(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');

        // Identical envelope to a scoped miss: a missing route and a foreign/missing
        // resource are indistinguishable from the body.
        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_duplicate_409_is_not_re_handled_by_the_central_handler(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Wishlist::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        Sanctum::actingAs($user);

        // The self-rendered 409 from Phase 4 must survive central normalization untouched.
        $this->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Product is already in your wishlist.']);
    }
}
