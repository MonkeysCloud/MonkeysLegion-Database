<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use MonkeysLegion\Database\Cache\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RedisCacheAdapterErrorTest extends TestCase
{
    public function testRedisExceptionHandling(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');

        $adapter = new RedisCacheAdapter($redis, 'test:');

        // Test get operation with Redis exception
        $redis->method('get')->willThrowException(new \RedisException('Redis error'));

        $item = $adapter->getItem('test_key');
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testSaveWithRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('set')->willThrowException(new \RedisException('Redis error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $item = new CacheItem('test_key');
        $item->set('test_value');

        $result = $adapter->save($item);
        $this->assertFalse($result);

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testDeleteWithRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('del')->willThrowException(new \RedisException('Redis error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $result = $adapter->deleteItem('test_key');
        $this->assertFalse($result);

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testHasItemWithRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('exists')->willThrowException(new \RedisException('Redis error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $result = $adapter->hasItem('test_key');
        $this->assertFalse($result);

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testClearWithRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('scan')->willThrowException(new \RedisException('Redis error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $result = $adapter->clear();
        $this->assertFalse($result);

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testGetItemsWithRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('mget')->willThrowException(new \RedisException('Redis error'));
        $redis->method('get')->willReturn(false); // Fallback to individual gets

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $items = iterator_to_array($adapter->getItems(['key1', 'key2']));

        $this->assertCount(2, $items);
        $this->assertFalse($items['key1']->isHit());
        $this->assertFalse($items['key2']->isHit());
    }

    public function testCommitWithPipelineException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('multi')->willThrowException(new \RedisException('Pipeline error'));
        $redis->method('set')->willReturn(true);
        $redis->method('setex')->willReturn(true);

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $item1 = new CacheItem('key1');
        $item1->set('value1');
        $adapter->saveDeferred($item1);

        $item2 = new CacheItem('key2');
        $item2->set('value2');
        $adapter->saveDeferred($item2);

        $result = $adapter->commit();
        $this->assertTrue($result); // Should succeed via fallback
    }

    public function testInvalidKeyValidation(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $this->expectException(InvalidArgumentException::class);
        $adapter->getItem('invalid/key');
    }

    public function testCorruptedDataHandling(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('get')->willReturn('corrupted_serialized_data');
        $redis->method('del')->willReturn(1); // Cleanup corrupted data

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $item = $adapter->getItem('corrupted_key');
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());

        $stats = $adapter->getStatistics();
        $this->assertGreaterThan(0, $stats['errors']);
    }

    public function testConnectionStatusWhenDisconnected(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')
            ->willReturnOnConsecutiveCalls('PONG', false); // First call succeeds, second fails

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $this->assertFalse($adapter->isConnected());
    }

    public function testStatisticsWithRedisInfoException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('info')->willThrowException(new \RedisException('Info error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $stats = $adapter->getStatistics();
        $this->assertArrayHasKey('redis_stats', $stats);
        $this->assertArrayHasKey('error', $stats['redis_stats']);
    }

    public function testGetConnectionInfoWithException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn('PONG');
        $redis->method('info')->willThrowException(new \RedisException('Info error'));

        $adapter = new RedisCacheAdapter($redis, 'test:');

        $info = $adapter->getConnectionInfo();
        $this->assertArrayHasKey('error', $info);
    }

    public function testCreateConnectionFailureScenarios(): void
    {
        // Test connection failure
        $this->expectException(CacheException::class);

        RedisCacheAdapter::createConnection('invalid-host', 6379, 1.0);
    }

    public function testMockRedisAuthentication(): void
    {
        // Mock successful authentication scenario
        $redis = $this->createMock(\Redis::class);
        $redis->method('connect')->willReturn(true);
        $redis->method('auth')->willReturn(true);
        $redis->method('select')->willReturn(true);
        $redis->method('ping')->willReturn('PONG');

        $adapter = new RedisCacheAdapter($redis, 'test:');
        $this->assertInstanceOf(RedisCacheAdapter::class, $adapter);
    }
}
