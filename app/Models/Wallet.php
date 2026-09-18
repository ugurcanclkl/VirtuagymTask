<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = ['public_id'];

    public function chainWallets(): HasMany
    {
        return $this->hasMany(ChainWallet::class);
    }
}
