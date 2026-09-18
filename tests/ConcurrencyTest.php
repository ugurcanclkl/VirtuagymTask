<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends TestCase
{
    public function test_two_processes_provisioning_the_same_wallet_return_one_record(): void
    {
        $user = $this->registerUser();
        $path = storage_path('framework/cache/race-'.Str::uuid().'.sqlite');
        DB::statement('VACUUM INTO ?', [$path]);
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/provision-in-process.php'), $path, (string) $user->wallet->id]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame($processes[0]->getOutput(), $processes[1]->getOutput());
            $pdo = new PDO('sqlite:'.$path);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM chain_wallets')->fetchColumn());
            $pdo = null;
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
