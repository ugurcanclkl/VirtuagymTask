<?php

namespace App\Wallets;

class DemoAddressGenerator implements AddressGenerator
{
    public function generate(string $walletPublicId, Chain $chain): string
    {
        // Deliberately invalid on real networks. No keys or real funds are involved.
        return $chain->value.'_'.substr(hash('sha256', $walletPublicId.'|'.$chain->value), 0, 40);
    }
}
