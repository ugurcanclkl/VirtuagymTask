<?php

namespace Tests;

use App\Actions\RegisterUser;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

abstract class TestCase extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require SQLite :memory:.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['logging.channels.single.path' => storage_path('logs/testing.log')]);
        Log::forgetChannel('single');
        file_put_contents(storage_path('logs/testing.log'), '');
    }

    protected function registration(string $email = 'operator@example.test'): array
    {
        return [
            'name' => 'Test operator',
            'email' => $email,
            'password' => 'test-password-only',
            'password_confirmation' => 'test-password-only',
        ];
    }

    protected function registerUser(string $email = 'operator@example.test'): User
    {
        return app(RegisterUser::class)->handle($this->registration($email));
    }

    protected function authenticate(User $user, array $abilities = ['wallet:access']): string
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('test', $abilities)->plainTextToken;
        $this->withToken($token);
        return $token;
    }

    protected function addressEndpoint(User $user): string
    {
        return '/api/wallets/'.$user->wallet->id.'/deposit-address';
    }

    protected function runNextJob(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'wallets', '--once' => true, '--sleep' => 0,
        ]));
    }
}
