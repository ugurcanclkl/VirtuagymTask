<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChainWallet extends Model
{
    protected $fillable = ['chain', 'public_address'];

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }
}
