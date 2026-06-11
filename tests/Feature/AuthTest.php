<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_succeeds_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'created_at'], 'token']]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('ada@example.com', $response->json('data.user.email'));

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        // Password is stored hashed, never in plain text.
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_register_user_payload_omits_password_and_remember_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_login_user_payload_omits_password_and_remember_token(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_validation_error_envelope_has_message_and_errors_keys(): void
    {
        // The 422 envelope must carry both `message` and `errors` so clients can rely on it.
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $invalidFields
     */
    #[DataProvider('invalidRegistrationProvider')]
    public function test_register_validation_rejects_bad_input(array $payload, array $invalidFields): void
    {
        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors($invalidFields);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
     */
    public static function invalidRegistrationProvider(): array
    {
        $valid = [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        return [
            'missing all fields' => [[], ['name', 'email', 'password']],
            'invalid email' => [array_merge($valid, ['email' => 'not-an-email']), ['email']],
            'unconfirmed password' => [array_merge($valid, ['password_confirmation' => 'mismatch']), ['password']],
            'short password' => [array_merge($valid, ['password' => 'short', 'password_confirmation' => 'short']), ['password']],
        ];
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_succeeds_and_returns_a_token(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_validation_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->json('data.token');

        $auth = ['Authorization' => "Bearer {$token}"];
        // Sanctum's plain-text token is "{id}|{secret}"; grab the row id to assert deletion.
        $tokenId = (int) explode('|', $token, 2)[0];

        $this->postJson('/api/logout', [], $auth)
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out.']);

        // Revocation is proven at the data layer, independent of the guard: the row is gone.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // forgetGuards: Sanctum's RequestGuard caches the resolved user within a single test
        // process, so the next call must re-authenticate from scratch as a fresh HTTP request
        // (separate processes in prod) would, instead of seeing the stale cached user.
        $this->app['auth']->forgetGuards();

        // The same token is now revoked and rejected on a protected route.
        $this->postJson('/api/logout', [], $auth)
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json');
    }

    // Unauthenticated-access checks for every protected route (logout included) now live in
    // the consolidated AuthGuardTest.
}
