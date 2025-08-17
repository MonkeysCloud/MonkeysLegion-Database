<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;

class RedisCacheAdapterTest extends TestCase
{
    private ?\Redis $redis = null;
    private ?RedisCacheAdapter $adapter = null;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not available');
        }

        try {
            // Use the factory method instead of manual connection
            $this->redis = RedisCacheAdapter::createConnection('127.0.0.1', 6379, 1.0, null, 15);
            $this->redis->flushDB(); // Clear test database

            $this->adapter = new RedisCacheAdapter($this->redis, 'test:');
        } catch (CacheException $e) {
            $this->markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            try {
                $this->redis->flushDB(); // Clean up test data
                $this->redis->close();
            } catch (\RedisException $e) {
                // Ignore cleanup errors
            }
        }
    }

    public function testConstructorWithValidRedis(): void
    {
        $redis = $this->getMockBuilder(\Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ping'])
            ->getMock();

        $redis->expects($this->once())
            ->method('ping')
            ->willReturn('PONG'); // Return valid PONG response

        $adapter = new RedisCacheAdapter($redis, 'test:');
        $this->assertInstanceOf(RedisCacheAdapter::class, $adapter);
    }

    public function testConstructorThrowsExceptionOnFailedPing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RedisException('Connection failed'));

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis connection failed');

        new RedisCacheAdapter($redis);
    }

    public function testConstructorThrowsExceptionOnInvalidPing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('ping')
            ->willReturn('INVALID_RESPONSE'); // Invalid ping response

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis connection failed - ping returned');

        new RedisCacheAdapter($redis);
    }

    public function testCreateConnectionSuccess(): void
    {
        if (!$this->isRedisAvailable()) {
            $this->markTestSkipped('Redis server not available');
        }

        $redis = RedisCacheAdapter::createConnection('127.0.0.1', 6379);
        $this->assertInstanceOf(\Redis::class, $redis);

        $ping = $redis->ping();
        $validResponses = ['+PONG', 'PONG', 1, true, '1'];
        $this->assertTrue(in_array($ping, $validResponses, true));
        $redis->close();
    }

    public function testCreateConnectionWithAuth(): void
    {
        if (!$this->isRedisAvailable()) {
            $this->markTestSkipped('Redis server not available');
        }

        // Test without auth (should work on default Redis)
        $redis = RedisCacheAdapter::createConnection(
            host: '127.0.0.1',
            port: 6379,
            database: 1
        );

        $this->assertInstanceOf(\Redis::class, $redis);
        $redis->close();
    }

    public function testCreateConnectionFailure(): void
    {
        $this->expectException(CacheException::class);

        RedisCacheAdapter::createConnection('invalid-host', 6379, 1.0);
    }

    public function testGetItemNotFound(): void
    {
        $item = $this->adapter->getItem('nonexistent');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertEquals('nonexistent', $item->getKey());
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());
    }

    public function testSaveAndGetItem(): void
    {
        $item = new CacheItem('test_key');
        $item->set('test_value');

        $this->assertTrue($this->adapter->save($item));

        $retrievedItem = $this->adapter->getItem('test_key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('test_value', $retrievedItem->get());
    }

    public function testSaveItemWithExpiration(): void
    {
        $item = new CacheItem('expiring_key');
        $item->set('expiring_value');
        $item->expiresAfter(1); // 1 second

        $this->assertTrue($this->adapter->save($item));

        $retrievedItem = $this->adapter->getItem('expiring_key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('expiring_value', $retrievedItem->get());

        // Wait for expiration
        sleep(2);

        $expiredItem = $this->adapter->getItem('expiring_key');
        $this->assertFalse($expiredItem->isHit());
        $this->assertNull($expiredItem->get());
    }

    public function testSaveItemWithPastExpiration(): void
    {
        $item = new CacheItem('past_expiry');
        $item->set('value');
        $item->expiresAt((new \DateTimeImmutable())->modify('-1 hour'));

        $result = $this->adapter->save($item);
        $this->assertFalse($result); // Should fail to save expired item
    }

    public function testHasItem(): void
    {
        $this->assertFalse($this->adapter->hasItem('test_key'));

        $item = new CacheItem('test_key');
        $item->set('test_value');
        $this->adapter->save($item);

        $this->assertTrue($this->adapter->hasItem('test_key'));
    }

    public function testDeleteItem(): void
    {
        $item = new CacheItem('delete_key');
        $item->set('delete_value');
        $this->adapter->save($item);

        $this->assertTrue($this->adapter->hasItem('delete_key'));
        $this->assertTrue($this->adapter->deleteItem('delete_key'));
        $this->assertFalse($this->adapter->hasItem('delete_key'));
    }

    public function testDeleteNonExistentItem(): void
    {
        $this->assertTrue($this->adapter->deleteItem('nonexistent'));
    }

    public function testGetItems(): void
    {
        // Save test items
        $item1 = new CacheItem('key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $items = iterator_to_array($this->adapter->getItems(['key1', 'key2', 'key3']));

        $this->assertCount(3, $items);
        $this->assertTrue($items['key1']->isHit());
        $this->assertEquals('value1', $items['key1']->get());
        $this->assertTrue($items['key2']->isHit());
        $this->assertEquals('value2', $items['key2']->get());
        $this->assertFalse($items['key3']->isHit());
    }

    public function testGetItemsEmpty(): void
    {
        $items = iterator_to_array($this->adapter->getItems([]));
        $this->assertEmpty($items);
    }

    public function testDeleteItems(): void
    {
        $item1 = new CacheItem('key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $this->assertTrue($this->adapter->deleteItems(['key1', 'key2']));

        $this->assertFalse($this->adapter->hasItem('key1'));
        $this->assertFalse($this->adapter->hasItem('key2'));
    }

    public function testDeleteItemsEmpty(): void
    {
        $this->assertTrue($this->adapter->deleteItems([]));
    }

    public function testClear(): void
    {
        $item1 = new CacheItem('key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $this->assertTrue($this->adapter->clear());

        $this->assertFalse($this->adapter->hasItem('key1'));
        $this->assertFalse($this->adapter->hasItem('key2'));
    }

    public function testClearByPrefix(): void
    {
        $item1 = new CacheItem('user_1');
        $item1->set('user1_data');
        $this->adapter->save($item1);

        $item2 = new CacheItem('user_2');
        $item2->set('user2_data');
        $this->adapter->save($item2);

        $item3 = new CacheItem('post_1');
        $item3->set('post1_data');
        $this->adapter->save($item3);

        $this->assertTrue($this->adapter->clearByPrefix('user_'));

        $this->assertFalse($this->adapter->hasItem('user_1'));
        $this->assertFalse($this->adapter->hasItem('user_2'));
        $this->assertTrue($this->adapter->hasItem('post_1'));
    }

    public function testSaveDeferred(): void
    {
        $item = new CacheItem('deferred_key');
        $item->set('deferred_value');

        $this->assertTrue($this->adapter->saveDeferred($item));
        $this->assertFalse($this->adapter->hasItem('deferred_key'));

        $this->assertTrue($this->adapter->commit());
        $this->assertTrue($this->adapter->hasItem('deferred_key'));
    }

    public function testCommitEmpty(): void
    {
        $this->assertTrue($this->adapter->commit());
    }

    public function testSaveWithPsrCacheItemInterface(): void
    {
        $mockItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockItem->method('getKey')->willReturn('psr_key');
        $mockItem->method('get')->willReturn('psr_value');

        $this->assertTrue($this->adapter->save($mockItem));
        $this->assertTrue($this->adapter->hasItem('psr_key'));
    }

    public function testStatistics(): void
    {
        // Initially empty
        $stats = $this->adapter->getStatistics();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['writes']);
        $this->assertEquals(0, $stats['deletes']);
        $this->assertEquals(0.0, $stats['hit_ratio']);

        // Save an item
        $item = new CacheItem('stats_key');
        $item->set('stats_value');
        $this->adapter->save($item);

        // Hit
        $this->adapter->getItem('stats_key');

        // Miss
        $this->adapter->getItem('nonexistent');

        $stats = $this->adapter->getStatistics();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(1, $stats['writes']);
        $this->assertEquals(50.0, $stats['hit_ratio']);
        $this->assertArrayHasKey('redis_stats', $stats);
        $this->assertArrayHasKey('connection_status', $stats);
    }

    public function testResetStatistics(): void
    {
        // Generate some statistics
        $item = new CacheItem('test_key');
        $item->set('test_value');
        $this->adapter->save($item);
        $this->adapter->getItem('test_key');

        $stats = $this->adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['hits']);
        $this->assertGreaterThan(0, $stats['writes']);

        $this->adapter->resetStatistics();

        $stats = $this->adapter->getStatistics();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['writes']);
        $this->assertEquals(0, $stats['deletes']);
    }

    public function testIsConnected(): void
    {
        $this->assertTrue($this->adapter->isConnected());
    }

    public function testGetConnectionInfo(): void
    {
        $info = $this->adapter->getConnectionInfo();
        $this->assertIsArray($info);

        if (!isset($info['error'])) {
            $this->assertArrayHasKey('redis_version', $info);
        }
    }

    public function testSerializationHandling(): void
    {
        $complexData = [
            'string' => 'test',
            'number' => 42,
            'array' => [1, 2, 3],
            'object' => (object)['prop' => 'value'],
            'boolean' => true,
            'null' => null
        ];

        $item = new CacheItem('complex_data');
        $item->set($complexData);
        $this->adapter->save($item);

        $retrieved = $this->adapter->getItem('complex_data');
        $this->assertTrue($retrieved->isHit());
        $this->assertEquals($complexData, $retrieved->get());
    }

    public function testBooleanFalseValue(): void
    {
        $item = new CacheItem('false_value');
        $item->set(false);
        $this->adapter->save($item);

        $retrieved = $this->adapter->getItem('false_value');
        $this->assertTrue($retrieved->isHit());
        $this->assertFalse($retrieved->get());
    }

    private function createMockRedis(): \PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(\Redis::class);
    }

    private function isRedisAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $redis = RedisCacheAdapter::createConnection('127.0.0.1', 6379, 1.0);
            $redis->close();
            return true;
        } catch (CacheException $e) {
            return false;
        }
    }
}
