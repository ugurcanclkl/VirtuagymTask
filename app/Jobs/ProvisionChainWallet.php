<?php

namespace App\Jobs;

use App\Actions\EnsureChainWallet;
use App\Wallets\Chain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionChainWallet implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 20;
    public array $backoff = [2, 10];

    public function __construct(public int $walletId, public Chain $chain)
    {
        $this->onConnection('database')->onQueue('wallets')->beforeCommit();
    }

    public function handle(EnsureChainWallet $wallets): void
    {
        $wallets->handle($this->walletId, $this->chain);
    }
}
