<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Enum\Constants;
use PHPUnit\Framework\TestCase;

class ConcurrencySimulationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache_concurrency_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestCache();
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function cleanupTestCache(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = array_merge(
            glob($this->tempDir . '/*.cache') ?: [],
            glob($this->tempDir . '/*.lock') ?: []
        );

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testConcurrencyWithExternalProcess(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open function is not available');
        }

        $adapter = new FileSystemAdapter($this->tempDir);
        $key = 'concurrent_process_key';

        // Create a PHP script to run in separate process
        $script = $this->createConcurrentScript($key);
        $scriptFile = $this->tempDir . '/concurrent_test.php';
        file_put_contents($scriptFile, $script);

        // Start external process
        $process = proc_open(
            "php $scriptFile",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ],
            $pipes
        );

        if (!is_resource($process)) {
            $this->markTestSkipped('Could not start external process');
        }

        // Simultaneously save from main process
        $item = new CacheItem($key);
        $item->set('main_process_value');
        $result1 = $adapter->save($item);

        // Wait for external process to complete
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Both should succeed
        $this->assertTrue($result1);
        $this->assertEquals(0, $exitCode, "External process failed: $error");

        // Verify one value won
        $finalItem = $adapter->getItem($key);
        $this->assertTrue($finalItem->isHit());

        // Clean up
        @unlink($scriptFile);
    }

    private function createConcurrentScript(string $key): string
    {
        $tempDir = $this->tempDir;
        $autoload = realpath(__DIR__ . '/../../../vendor/autoload.php');

        return <<<PHP
        <?php
        require_once '$autoload';

        use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
        use MonkeysLegion\Database\Cache\Items\CacheItem;

        try {
            \$adapter = new FileSystemAdapter('$tempDir');
            \$item = new CacheItem('$key');
            \$item->set('external_process_value');
            \$result = \$adapter->save(\$item);
            echo \$result ? 'SUCCESS' : 'FAILED';
            exit(0);
        } catch (Exception \$e) {
            echo 'ERROR: ' . \$e->getMessage();
            exit(1);
        }
    PHP;
    }

    public function testRapidFireOperations(): void
    {
        $adapter = new FileSystemAdapter($this->tempDir);
        $baseKey = 'rapid_fire_key';

        // Perform rapid operations
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $key = $baseKey . '_' . $i;
            $item = new CacheItem($key);
            $item->set("value_$i");
            $results[] = $adapter->save($item);

            // Immediately try to read
            $retrievedItem = $adapter->getItem($key);
            $this->assertTrue($retrievedItem->isHit());
            $this->assertEquals("value_$i", $retrievedItem->get());
        }

        // All saves should succeed
        foreach ($results as $result) {
            $this->assertTrue($result);
        }
    }

    public function testStaleDataReturn(): void
    {
        $adapter = new FileSystemAdapter($this->tempDir);
        $key = 'stale_data_key';

        // Create and save an item that will expire
        $item = new CacheItem($key);
        $item->set('stale_value');
        $item->expiresAfter(1);
        $adapter->save($item);

        // Wait for expiration
        sleep(2);

        // Manually create a lock to simulate regeneration
        $lockFile = $adapter->getLockFilename($key);
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 10,
            'process_id' => 99999 // Different PID
        ];
        file_put_contents($lockFile, serialize($lockData));
        
        // Should return stale data
        $retrievedItem = $adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit()); // Marked as miss
        $this->assertFileExists($lockFile);
        $this->assertEquals('stale_value', $retrievedItem->get()); // But returns stale data

        // Clean up
        @unlink($lockFile);
    }
}
