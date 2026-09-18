<?php

namespace App\Actions;

use App\Exceptions\RejectedDeposit;
use App\Models\ChainWallet;
use App\Models\Deposit;
use App\Wallets\Chain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordDeposit
{
    public function handle(array $payload): Deposit
    {
        $data = Validator::make($payload, [
            'chain' => ['required', Rule::enum(Chain::class)],
            'address' => ['required', 'string', 'max:100'],
            'tx_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'event_index' => ['required', 'integer', 'min:0', 'max:65535'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ])->validate();

        return DB::transaction(function () use ($data): Deposit {
            $wallet = ChainWallet::where('chain', $data['chain'])
                ->where('public_address', $data['address'])->first();
            if (! $wallet) {
                throw new RejectedDeposit('Unknown deposit address.');
            }

            $deposit = Deposit::query()->createOrFirst([
                'chain' => $data['chain'],
                'tx_hash' => $data['tx_hash'],
                'event_index' => $data['event_index'],
            ], [
                'chain_wallet_id' => $wallet->id,
                'amount_minor' => $data['amount_minor'],
            ]);

            if ((int) $deposit->chain_wallet_id !== $wallet->id || $deposit->amount_minor !== (int) $data['amount_minor']) {
                throw new RejectedDeposit('Conflicting redelivery.');
            }

            return $deposit;
        }, attempts: 3);
    }
}
