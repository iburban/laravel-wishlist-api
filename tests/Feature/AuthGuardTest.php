<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every protected route must reject an unauthenticated request with 401 JSON
     * (not an HTML redirect to a login page).
     *
     * @param  'get'|'post'|'delete'  $method
     */
    #[DataProvider('protectedRouteProvider')]
    public function test_protected_routes_require_authentication(string $method, string $uri): void
    {
        $response = $this->json(strtoupper($method), $uri);

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedRouteProvider(): array
    {
        return [
            'logout' => ['post', '/api/logout'],
            'list wishlist' => ['get', '/api/wishlist'],
            'add to wishlist' => ['post', '/api/wishlist'],
            'remove from wishlist' => ['delete', '/api/wishlist/1'],
        ];
    }

    public function test_products_route_is_public(): void
    {
        Product::factory()->count(2)->create();

        // No token: the catalog is reachable and returns data, not 401.
        $this->getJson('/api/products')->assertOk();
    }

    public function test_register_and_login_routes_are_reachable_without_a_token(): void
    {
        // No token: the guard does not block these. They reach validation (422),
        // which proves they are public — the request was processed, not rejected at the guard.
        $this->postJson('/api/register', [])->assertStatus(422);
        $this->postJson('/api/login', [])->assertStatus(422);
    }
}
