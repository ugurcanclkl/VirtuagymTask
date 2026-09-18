<?php

namespace App\Console\Commands;

use App\Messaging\DepositConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class ConsumeDeposits extends Command
{
    protected $signature = 'deposits:consume {--once : Process one delivery and exit}';
    protected $description = 'Consume demo deposit messages from RabbitMQ';

    public function handle(DepositConsumer $consumer): int
    {
        $connection = null;
        try {
            $config = config('rabbitmq');
            if (! $config['user'] || ! $config['password']) {
                $this->error('Set RABBITMQ_USER and RABBITMQ_PASSWORD in your local environment.');
                return self::FAILURE;
            }
            $connection = new AMQPStreamConnection(
                $config['host'], $config['port'], $config['user'], $config['password'], $config['vhost'],
            );
            $channel = $connection->channel();
            $channel->exchange_declare($config['dead_letter_exchange'], 'fanout', false, true, false);
            $channel->queue_declare($config['dead_letter_queue'], false, true, false, false);
            $channel->queue_bind($config['dead_letter_queue'], $config['dead_letter_exchange']);
            $channel->queue_declare($config['queue'], false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $config['dead_letter_exchange'],
            ]));
            $channel->basic_qos(0, 1, false);
            $channel->basic_consume($config['queue'], 'wallet-demo', false, false, false, false,
                function (AMQPMessage $message) use ($consumer, $channel): void {
                    $consumer->handle(
                        $message->getBody(),
                        fn () => $message->ack(),
                        fn (bool $requeue) => $message->reject($requeue),
                    );
                    if ($this->option('once')) {
                        $channel->basic_cancel('wallet-demo');
                    }
                },
            );
            $this->info('Listening for demo deposits. Press Ctrl+C to stop.');
            $channel->consume();
            return self::SUCCESS;
        } catch (Throwable $error) {
            Log::error('Deposit listener stopped', ['exception_type' => get_class($error)]);
            $this->error('Listener stopped. Check the application log and broker connection.');
            return self::FAILURE;
        } finally {
            if ($connection) {
                try {
                    $connection->close();
                } catch (Throwable) {
                    Log::warning('Broker connection close failed');
                }
            }
        }
    }
}
