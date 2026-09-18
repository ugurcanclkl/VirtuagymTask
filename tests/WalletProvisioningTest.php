<?php

namespace Tests;

use App\Models\ChainWallet;
use App\Models\User;
use App\Models\Wallet;
use App\Wallets\AddressGenerator;
use App\Wallets\Chain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletProvisioningTest extends TestCase
{
    public function test_registration_commits_user_wallet_and_jobs_without_generating_addresses(): void
    {
        $this->postJson('/api/auth/register', $this->registration('Operator@EXAMPLE.test'))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'operator@example.test']);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('chain_wallets', 0);
        $payload = DB::table('jobs')->first()->payload;
        $this->assertStringNotContainsString('test-password-only', $payload);
        $this->assertStringNotContainsString('operator@example.test', $payload);
    }

    public function test_queue_worker_creates_both_addresses_before_the_user_asks(): void
    {
        $user = $this->registerUser();
        $this->runNextJob();
        $this->runNextJob();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('chain_wallets', 2);
        $this->authenticate($user);
        $id = ChainWallet::where('chain', 'demo-eth')->value('id');
        $this->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])
            ->assertOk()->assertJsonPath('data.chain_wallet.id', $id);
    }

    public function test_request_before_worker_runs_creates_address_and_later_job_reuses_it(): void
    {
        $user = $this->registerUser();
        $this->authenticate($user);
        $response = $this->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertOk();
        $address = $response->json('data.chain_wallet.public_address');
        $this->assertStringStartsWith('demo-eth_', $address);
        $this->assertDatabaseCount('chain_wallets', 1);
        $this->assertDatabaseCount('jobs', 2);
        $this->runNextJob();
        $this->runNextJob();
        $this->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])
            ->assertOk()->assertJsonPath('data.chain_wallet.public_address', $address);
        $this->assertDatabaseCount('chain_wallets', 2);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_insert_failure_rolls_back_user_wallet_and_already_inserted_job(): void
    {
        DB::unprepared("CREATE TRIGGER fail_second_job BEFORE INSERT ON jobs WHEN (SELECT COUNT(*) FROM jobs) = 1 BEGIN SELECT RAISE(ABORT, 'private-sql-detail'); END");
        $response = $this->postJson('/api/auth/register', $this->registration())->assertStatus(500)
            ->assertJsonPath('data.status_code', 9000);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('jobs', 0);
        $log = file_get_contents(storage_path('logs/testing.log'));
        $this->assertStringContainsString($response->json('request_id'), $log);
        foreach (['private-sql-detail', 'test-password-only', 'operator@example.test', 'insert into'] as $secret) {
            $this->assertStringNotContainsString($secret, $log.$response->getContent());
        }
    }

    public function test_address_failure_is_safe_and_can_be_retried_by_the_queued_job(): void
    {
        $user = $this->registerUser();
        $token = $this->authenticate($user);
        $generator = new class implements AddressGenerator {
            public int $calls = 0;
            public function generate(string $walletPublicId, Chain $chain): string
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('provider-secret-do-not-log');
                }
                return $chain->value.'_retry';
            }
        };
        $this->app->instance(AddressGenerator::class, $generator);
        $this->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertStatus(500);
        $this->assertDatabaseCount('chain_wallets', 0);
        $this->assertDatabaseCount('jobs', 2);
        $log = file_get_contents(storage_path('logs/testing.log'));
        $this->assertStringNotContainsString('provider-secret-do-not-log', $log);
        $this->assertStringNotContainsString($token, $log);
        $this->runNextJob();
        $this->assertDatabaseHas('chain_wallets', ['chain' => 'demo-eth']);
    }

    public function test_other_users_wallet_and_insufficient_token_ability_are_denied(): void
    {
        $owner = $this->registerUser();
        $other = $this->registerUser('other@example.test');
        $this->authenticate($other);
        $this->postJson($this->addressEndpoint($owner), ['chain' => 'demo-eth'])->assertNotFound();
        $this->authenticate($owner, []);
        $this->postJson($this->addressEndpoint($owner), ['chain' => 'demo-eth'])->assertForbidden();
        $this->assertDatabaseCount('chain_wallets', 0);
    }

    public function test_failed_background_job_is_released_and_succeeds_on_retry(): void
    {
        $this->registerUser();
        $this->app->instance(AddressGenerator::class, new class implements AddressGenerator {
            private int $calls = 0;
            public function generate(string $walletPublicId, Chain $chain): string
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('private-provider-error');
                }
                return $chain->value.'_recovered';
            }
        });
        $this->runNextJob();
        $this->assertDatabaseCount('chain_wallets', 0);
        $this->assertSame(1, DB::table('jobs')->where('attempts', 1)->count());
        $this->assertStringNotContainsString('private-provider-error', file_get_contents(storage_path('logs/testing.log')));
        DB::table('jobs')->update(['available_at' => 0]);
        $this->runNextJob();
        $this->runNextJob();
        $this->assertDatabaseHas('chain_wallets', ['chain' => 'demo-eth']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_unauthenticated_request_cannot_create_an_address(): void
    {
        $user = $this->registerUser();
        $this->postJson($this->addressEndpoint($user), ['chain' => 'demo-eth'])->assertUnauthorized();
        $this->assertDatabaseCount('chain_wallets', 0);
    }

    public function test_unsupported_chain_and_duplicate_email_are_rejected(): void
    {
        $user = $this->registerUser();
        $this->postJson('/api/auth/register', $this->registration('OPERATOR@example.test'))->assertUnprocessable();
        $this->authenticate($user);
        $this->postJson($this->addressEndpoint($user), ['chain' => 'real-mainnet'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('chain_wallets', 0);
    }

    public function test_password_confirmation_byte_limit_and_required_fields_are_validated(): void
    {
        $this->postJson('/api/auth/register', [])->assertUnprocessable();
        $this->postJson('/api/auth/register', array_replace($this->registration(), ['password_confirmation' => 'mismatch']))
            ->assertUnprocessable();
        $long = str_repeat('é', 40);
        $this->postJson('/api/auth/register', array_replace($this->registration(), ['password' => $long, 'password_confirmation' => $long]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unknown_fields_do_not_control_wallet_ownership_or_address(): void
    {
        $this->postJson('/api/auth/register', $this->registration() + ['id' => 999, 'is_admin' => true])->assertCreated();
        $user = User::firstOrFail();
        $this->assertNotSame(999, $user->id);
        $this->authenticate($user);
        $this->postJson($this->addressEndpoint($user), [
            'chain' => 'demo-eth', 'public_address' => 'attacker-address', 'user_id' => 999,
        ])->assertOk();
        $this->assertSame($user->id, Wallet::first()->user_id);
        $this->assertNotSame('attacker-address', ChainWallet::first()->public_address);
    }
}
