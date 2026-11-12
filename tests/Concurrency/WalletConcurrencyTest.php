<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real concurrency tests: separate PHP processes hit the same SQLite file at the same instant.
 * The in-memory SQLite used by the other suites cannot reproduce this (one connection, one process).
 */
class WalletConcurrencyTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cobuccio-concurrency-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->databaseFile);

        $setup = $this->runWorkers([['setup']])[0];
        $this->assertTrue($setup['ok'], $setup['error'] ?? '');
    }

    protected function tearDown(): void
    {
        foreach ([$this->databaseFile, $this->databaseFile.'-wal', $this->databaseFile.'-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_two_simultaneous_transfers_cannot_spend_the_same_balance(): void
    {
        $startAt = (string) (microtime(true) + 1.5);
        $results = $this->runWorkers([['transfer', $startAt], ['transfer', $startAt]]);

        $successes = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $this->assertCount(1, $successes, 'Exactly one transfer must succeed: '.json_encode($results));

        $state = $this->runWorkers([['inspect']])[0];
        $this->assertSame(2000, $state['sender']);
        $this->assertSame(8000, $state['receiver']);
        $this->assertSame(1, $state['transfers']);
    }

    public function test_simultaneous_retries_with_the_same_idempotency_key_credit_only_once(): void
    {
        $startAt = (string) (microtime(true) + 1.5);
        $results = $this->runWorkers([
            ['deposit', $startAt, 'same-key-001'],
            ['deposit', $startAt, 'same-key-001'],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], 'Both retries must get the original transaction: '.json_encode($results));
        }
        $this->assertSame($results[0]['id'], $results[1]['id']);

        $state = $this->runWorkers([['inspect']])[0];
        $this->assertSame(5000, $state['receiver']);
        $this->assertSame(1, $state['deposits']);
    }

    /**
     * Starts every worker in parallel and returns their decoded JSON outputs (in the same order).
     *
     * @param  list<list<string>>  $workerArgs
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $workerArgs): array
    {
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->databaseFile,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $processes = [];
        foreach ($workerArgs as $args) {
            $command = array_merge([PHP_BINARY, __DIR__.'/wallet_worker.php'], $args);
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start worker.');
            }
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($stdout, true);
            $results[] = $decoded ?? ['ok' => false, 'error' => trim($stdout.' '.$stderr)];
        }

        return $results;
    }
}
