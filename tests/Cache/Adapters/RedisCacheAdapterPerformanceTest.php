<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;

class RedisCacheAdapterPerformanceTest extends TestCase
{
    private ?\Redis $redis = null;
    private ?RedisCacheAdapter $adapter = null;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not available');
        }

        try {
            // Use the factory method with database 14 for performance testing
            $this->redis = RedisCacheAdapter::createConnection('127.0.0.1', 6379, 1.0, null, 14);
            $this->redis->flushDB();

            $this->adapter = new RedisCacheAdapter($this->redis, 'perf:');
        } catch (CacheException $e) {
            $this->markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            try {
                $this->redis->flushDB();
                $this->redis->close();
            } catch (\RedisException $e) {
                // Ignore cleanup errors
            }
        }
    }

    public function testBatchOperationsPerformance(): void
    {
        $itemCount = 50; // Reduced for CI environments
        $keys = [];

        // Test batch save via deferred
        $startTime = microtime(true);

        for ($i = 0; $i < $itemCount; $i++) {
            $key = "batch_key_$i";
            $keys[] = $key;

            $item = new CacheItem($key);
            $item->set("value_$i");
            $this->adapter->saveDeferred($item);
        }

        $this->adapter->commit();
        $batchSaveTime = microtime(true) - $startTime;

        // Test batch get via getItems
        $startTime = microtime(true);
        $items = iterator_to_array($this->adapter->getItems($keys));
        $batchGetTime = microtime(true) - $startTime;

        // Verify all items were saved and retrieved
        $this->assertCount($itemCount, $items);
        foreach ($items as $key => $item) {
            $this->assertTrue($item->isHit());
            $expectedValue = str_replace('batch_key_', 'value_', $key);
            $this->assertEquals($expectedValue, $item->get());
        }

        // Performance should be reasonable (adjust thresholds as needed)
        $this->assertLessThan(10.0, $batchSaveTime, "Batch save took too long: {$batchSaveTime}s");
        $this->assertLessThan(5.0, $batchGetTime, "Batch get took too long: {$batchGetTime}s");
    }

    public function testSerializationPerformance(): void
    {
        $largeData = array_fill(0, 1000, 'test_data_' . str_repeat('x', 100));

        $item = new CacheItem('large_data');
        $item->set($largeData);

        $startTime = microtime(true);
        $result = $this->adapter->save($item);
        $saveTime = microtime(true) - $startTime;

        $this->assertTrue($result);

        $startTime = microtime(true);
        $retrievedItem = $this->adapter->getItem('large_data');
        $getTime = microtime(true) - $startTime;

        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals($largeData, $retrievedItem->get());

        // Should handle large data efficiently
        $this->assertLessThan(1.0, $saveTime, "Large data save too slow: {$saveTime}s");
        $this->assertLessThan(1.0, $getTime, "Large data get too slow: {$getTime}s");
    }
}
