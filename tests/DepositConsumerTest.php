<?php

namespace Tests;

use App\Actions\EnsureChainWallet;
use App\Messaging\DepositConsumer;
use App\Models\Deposit;
use App\Wallets\Chain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DepositConsumerTest extends TestCase
{
    private function message(): array
    {
        $user = $this->registerUser();
        $wallet = app(EnsureChainWallet::class)->handle($user->wallet->id, Chain::Ethereum);
        return ['chain' => 'demo-eth', 'address' => $wallet->public_address, 'tx_hash' => str_repeat('a', 64), 'event_index' => 0, 'amount_minor' => 12000];
    }

    private function deliver(string $body): array
    {
        $events = [];
        app(DepositConsumer::class)->handle($body,
            function () use (&$events): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertGreaterThan(0, Deposit::count());
                $events[] = 'ack';
            },
            function (bool $requeue) use (&$events): void { $events[] = $requeue ? 'retry' : 'reject'; },
        );
        return $events;
    }

    public function test_ack_is_after_commit_and_redelivery_does_not_duplicate_credit(): void
    {
        $body = json_encode($this->message());
        $this->assertSame(['ack'], $this->deliver($body));
        $this->assertSame(['ack'], $this->deliver($body));
        $this->assertDatabaseCount('deposits', 1);
        $this->assertSame(12000, (int) Deposit::sum('amount_minor'));
    }

    public function test_same_transaction_with_different_event_index_is_a_separate_deposit(): void
    {
        $data = $this->message();
        $this->deliver(json_encode($data));
        $data['event_index'] = 1;
        $this->assertSame(['ack'], $this->deliver(json_encode($data)));
        $this->assertDatabaseCount('deposits', 2);
    }

    public function test_conflicting_redelivery_is_rejected_without_changing_credit(): void
    {
        $data = $this->message();
        $this->deliver(json_encode($data));
        $data['amount_minor'] = 999;
        $this->assertSame(['reject'], $this->deliver(json_encode($data)));
        $this->assertSame(12000, Deposit::first()->amount_minor);
        $this->assertDatabaseCount('deposits', 1);
    }

    public function test_invalid_and_unknown_address_messages_are_rejected_without_requeue(): void
    {
        $data = $this->message();
        foreach (['{bad-json', 'null', '[]', str_repeat('x', 4097),
            json_encode(array_replace($data, ['amount_minor' => -1])),
            json_encode(array_replace($data, ['amount_minor' => 1.5])),
            json_encode(array_replace($data, ['address' => 'unknown'])),
            json_encode(array_replace($data, ['tx_hash' => "'; DROP TABLE users;--"])),
        ] as $body) {
            $this->assertSame(['reject'], $this->deliver($body));
        }
        $this->assertDatabaseCount('deposits', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_database_failure_requests_requeue_stops_and_redacts_details(): void
    {
        $data = $this->message();
        DB::unprepared("CREATE TRIGGER fail_deposit BEFORE INSERT ON deposits BEGIN SELECT RAISE(ABORT, 'secret-database-detail'); END");
        $events = [];
        try {
            app(DepositConsumer::class)->handle(json_encode($data),
                function () use (&$events): void { $events[] = 'ack'; },
                function (bool $requeue) use (&$events): void { $events[] = $requeue ? 'retry' : 'reject'; },
            );
            $this->fail('Expected the consumer to stop.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('consumer stopped', $error->getMessage());
        }
        $this->assertSame(['retry'], $events);
        $this->assertDatabaseCount('deposits', 0);
        $log = file_get_contents(storage_path('logs/testing.log'));
        $this->assertStringContainsString('delivery_id', $log);
        foreach (['secret-database-detail', $data['address'], $data['tx_hash']] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_lost_ack_can_be_redelivered_after_a_successful_commit(): void
    {
        $body = json_encode($this->message());
        try {
            app(DepositConsumer::class)->handle($body,
                function (): void { throw new RuntimeException('connection lost'); },
                function (): void { $this->fail('Do not reject after ACK failure.'); },
            );
            $this->fail('Expected ACK failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('connection lost', $error->getMessage());
        }
        $this->assertSame(['ack'], $this->deliver($body));
        $this->assertDatabaseCount('deposits', 1);
    }
}
