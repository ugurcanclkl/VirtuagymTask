<?php

namespace App\Actions;

use App\Models\ChainWallet;
use App\Models\Wallet;
use App\Wallets\AddressGenerator;
use App\Wallets\Chain;
use Illuminate\Support\Facades\DB;

class EnsureChainWallet
{
    public function __construct(private AddressGenerator $addresses) {}

    public function handle(int $walletId, Chain $chain): ChainWallet
    {
        return DB::transaction(function () use ($walletId, $chain): ChainWallet {
            $wallet = Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
            $existing = $wallet->chainWallets()->where('chain', $chain->value)->first();
            if ($existing) {
                return $existing;
            }

            $address = $this->addresses->generate($wallet->public_id, $chain);

            // The unique (wallet_id, chain) constraint protects the absent-row case too.
            return $wallet->chainWallets()->createOrFirst(
                ['chain' => $chain->value],
                ['public_address' => $address],
            );
        }, attempts: 3);
    }
}
