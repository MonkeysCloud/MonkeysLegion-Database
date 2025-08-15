<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Enum\Constants;
use PHPUnit\Framework\TestCase;

class FileSystemAdapterConcurrencyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache_concurrency_test_' . uniqid();
        $this->cleanupTestCache();
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

    public function testConcurrentSaveProtection(): void
    {
        $adapter = new FileSystemAdapter($this->tempDir);
        $key = 'concurrent_test_key';
        $results = [];

        // Simulate concurrent writes by using multiple adapter instances
        $adapters = [];
        for ($i = 0; $i < 3; $i++) {
            $adapters[] = new FileSystemAdapter($this->tempDir);
        }

        // Create items with different values
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $item = new CacheItem($key);
            $item->set("value_from_process_$i");
            $items[] = $item;
        }

        // Simulate concurrent saves
        foreach ($adapters as $index => $adapter) {
            $results[] = $adapter->save($items[$index]);
        }

        // All saves should succeed (true)
        foreach ($results as $result) {
            $this->assertTrue($result);
        }

        // Verify final state - one value should win
        $mainAdapter = new FileSystemAdapter($this->tempDir);
        $finalItem = $mainAdapter->getItem($key);
        $this->assertTrue($finalItem->isHit());
        $this->assertStringStartsWith('value_from_process_', $finalItem->get());
    }

    public function testMultipleAdapterInstances(): void
    {
        $key = 'multi_adapter_key';

        // Create two adapter instances pointing to same directory
        $adapter1 = new FileSystemAdapter($this->tempDir);
        $adapter2 = new FileSystemAdapter($this->tempDir);

        // Save with first adapter
        $item1 = new CacheItem($key);
        $item1->set('adapter1_value');
        $this->assertTrue($adapter1->save($item1));

        // Read with second adapter
        $item2 = $adapter2->getItem($key);
        $this->assertTrue($item2->isHit());
        $this->assertEquals('adapter1_value', $item2->get());

        // Update with second adapter
        $item2->set('adapter2_value');
        $this->assertTrue($adapter2->save($item2));

        // Read with first adapter
        $item3 = $adapter1->getItem($key);
        $this->assertTrue($item3->isHit());
        $this->assertEquals('adapter2_value', $item3->get());
    }

    public function testStatisticsUnderConcurrency(): void
    {
        $key = 'stats_concurrent_key';
        $mainAdapter = new FileSystemAdapter($this->tempDir);

        // Reset statistics
        $mainAdapter->resetStatistics();

        // Create multiple adapters
        $adapters = [];
        for ($i = 0; $i < 3; $i++) {
            $adapters[] = new FileSystemAdapter($this->tempDir);
        }

        // Perform operations with different adapters
        foreach ($adapters as $index => $adapter) {
            $item = new CacheItem($key . '_' . $index);
            $item->set("value_$index");
            $adapter->save($item);
            $adapter->getItem($key . '_' . $index); // Hit
            $adapter->getItem('nonexistent_' . $index); // Miss
        }

        // Check that main adapter statistics are independent
        $stats = $mainAdapter->getStatistics();
        $this->assertEquals(0, $stats['hits']); // Main adapter had no operations
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['writes']);
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
}
