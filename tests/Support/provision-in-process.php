<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = realpath($argv[1]);
$testDirectory = realpath(storage_path('framework/cache'));
if (! $database || dirname($database) !== $testDirectory || ! str_starts_with(basename($database), 'race-')) {
    throw new RuntimeException('Expected an isolated race-test database.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
Illuminate\Support\Facades\DB::purge();
$app->bind(App\Wallets\AddressGenerator::class, fn () => new class implements App\Wallets\AddressGenerator {
    public function generate(string $walletPublicId, App\Wallets\Chain $chain): string
    {
        usleep(200000); // Make competing processes overlap during provisioning.
        return (new App\Wallets\DemoAddressGenerator())->generate($walletPublicId, $chain);
    }
});
$wallet = $app->make(App\Actions\EnsureChainWallet::class)->handle((int) $argv[2], App\Wallets\Chain::Ethereum);
echo $wallet->id;
