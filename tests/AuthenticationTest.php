<?php

namespace Tests;

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationTest extends TestCase
{
    public function test_register_login_and_address_request_use_real_password_hash_and_bearer_token(): void
    {
        $registration = $this->postJson('/api/auth/register', $this->registration())->assertCreated();
        $user = \App\Models\User::firstOrFail();
        $registration->assertJsonMissingPath('data.user.password');
        $this->assertNotSame('test-password-only', $user->password);
        $this->assertTrue(Hash::check('test-password-only', $user->password));
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'test-password-only',
        ])->assertOk();
        $token = $response->json('data.access_token');
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), PersonalAccessToken::first()->token);
        $this->withToken($token)->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertOk();
    }

    public function test_bad_password_and_unknown_email_get_the_same_safe_error(): void
    {
        $user = $this->registerUser();
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-secret'])
                ->assertUnauthorized()->assertJsonPath('data.status_code', 1003);
        }
        $this->assertSame(0, PersonalAccessToken::count());
        $log = file_get_contents(storage_path('logs/testing.log'));
        $this->assertStringNotContainsString('wrong-secret', $log);
        $this->assertStringNotContainsString($user->email, $log);
    }

    public function test_expired_and_unknown_tokens_are_denied(): void
    {
        $user = $this->registerUser();
        $token = $this->authenticate($user);
        PersonalAccessToken::query()->update(['created_at' => now()->subMinutes(61)]);
        $this->withToken($token)->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken('not-a-real-token')->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->registerUser();
        $token = $this->authenticate($user);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->registerUser();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_login_does_not_accept_a_password_truncated_by_bcrypt(): void
    {
        $user = $this->registerUser();
        $password = str_repeat('é', 36); // 72 bytes, fewer than 72 characters.
        $user->update(['password' => Hash::make($password)]);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password.'extra'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => "invalid\0password"])
            ->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
