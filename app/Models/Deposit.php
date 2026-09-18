<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = ['chain_wallet_id', 'chain', 'tx_hash', 'event_index', 'amount_minor'];
    protected $casts = ['amount_minor' => 'integer', 'event_index' => 'integer'];
}
