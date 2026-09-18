<?php

namespace App\Wallets;

interface AddressGenerator
{
    public function generate(string $walletPublicId, Chain $chain): string;
}
