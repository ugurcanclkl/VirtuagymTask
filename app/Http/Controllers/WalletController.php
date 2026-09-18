<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\ApiResponse;
use App\Jobs\ProvisionChainWallet;
use App\Wallets\Chain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;

class WalletController
{
    public function address(Request $request, string $wallet): JsonResponse
    {
        if (! $request->user()->tokenCan('wallet:access')) {
            throw new ApiException('This token cannot access wallets.', 1004, 403);
        }
        $ownedWallet = $request->user()->wallet()->whereKey($wallet)->firstOrFail();
        $data = $request->validate(['chain' => ['required', Rule::enum(Chain::class)]]);
        $chain = Chain::from($data['chain']);
        $address = $ownedWallet->chainWallets()->where('chain', $chain->value)->first();

        if (! $address) {
            // Run the same job now, even if its queued copy has not run yet.
            Bus::dispatchSync(new ProvisionChainWallet($ownedWallet->id, $chain));
            $address = $ownedWallet->chainWallets()->where('chain', $chain->value)->firstOrFail();
        }

        return ApiResponse::success(['chain_wallet' => $address]);
    }
}
