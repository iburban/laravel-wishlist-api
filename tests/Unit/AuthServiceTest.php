<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $result = (new AuthService())->register([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertTrue($result['user']->exists);
        $this->assertIsString($result['token']);
        $this->assertNotEmpty($result['token']);
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_login_with_wrong_password_throws_validation_exception(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $this->expectException(ValidationException::class);

        (new AuthService())->login([
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);
    }
}
