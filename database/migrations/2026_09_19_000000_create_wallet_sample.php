<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->timestamps();
        });
        Schema::create('chain_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('chain');
            $table->string('public_address')->unique();
            $table->unique(['wallet_id', 'chain']);
            $table->timestamps();
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chain_wallet_id')->constrained();
            $table->string('chain');
            $table->string('tx_hash', 64);
            $table->unsignedInteger('event_index');
            $table->unsignedBigInteger('amount_minor');
            $table->unique(['chain', 'tx_hash', 'event_index']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['deposits', 'jobs', 'chain_wallets', 'wallets', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
