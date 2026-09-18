<?php

namespace App\Console\Commands;

use App\Messaging\DepositConsumer;
use Illuminate\Console\Command;

class ReplayDeposit extends Command
{
    protected $signature = 'deposits:replay {file : Local JSON message file}';
    protected $description = 'Run one message through the deposit handler without a broker';

    public function handle(DepositConsumer $consumer): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Message file is not readable.');
            return self::FAILURE;
        }
        $result = self::FAILURE;
        $consumer->handle(file_get_contents($path, length: 4097),
            function () use (&$result): void {
                $result = self::SUCCESS;
                $this->info('ACK: deposit committed or already recorded.');
            },
            function (bool $requeue): void {
                $this->warn($requeue ? 'REQUEUE: temporary failure.' : 'REJECT: invalid or conflicting message.');
            },
        );
        return $result;
    }
}
