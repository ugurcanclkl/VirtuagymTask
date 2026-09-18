<?php

namespace App\Actions;

use App\Exceptions\ApiException;
use App\Jobs\ProvisionChainWallet;
use App\Models\User;
use App\Wallets\Chain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterUser
{
    public function handle(array $data): User
    {
        try {
            return DB::transaction(function () use ($data): User {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
                $wallet = $user->wallet()->create(['public_id' => (string) Str::uuid()]);

                foreach (Chain::cases() as $chain) {
                    // The database queue shares this connection: jobs commit with the user.
                    Bus::dispatch(new ProvisionChainWallet($wallet->id, $chain));
                }

                return $user->load('wallet');
            });
        } catch (UniqueConstraintViolationException $error) {
            if (User::where('email', $data['email'])->exists()) {
                throw new ApiException('This email is already registered.', 1005, 422);
            }
            throw $error;
        }
    }
}
