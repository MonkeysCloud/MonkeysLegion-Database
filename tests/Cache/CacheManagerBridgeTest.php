<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache;

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Cache\CacheManagerBridge;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CacheManagerBridgeTest extends TestCase
{
    private CacheManagerBridge $bridge;
    private CacheManagerMock&MockObject $cacheManager;

    protected function setUp(): void
    {
        $this->cacheManager = $this->createMock(CacheManagerMock::class);
        $this->bridge = new CacheManagerBridge($this->cacheManager, 'test_prefix:');
    }

    public function testGetDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('get')
            ->with('test_prefix:key', 'default')
            ->willReturn('value');

        $result = $this->bridge->get('key', 'default');
        $this->assertEquals('value', $result);
    }

    public function testSetDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('set')
            ->with('test_prefix:key', 'value', 3600)
            ->willReturn(true);

        $result = $this->bridge->set('key', 'value', 3600);
        $this->assertTrue($result);
    }

    public function testDeleteDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('delete')
            ->with('test_prefix:key')
            ->willReturn(true);

        $result = $this->bridge->delete('key');
        $this->assertTrue($result);
    }

    public function testClearDelegatesToCacheManager(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $result = $this->bridge->clear();
        $this->assertTrue($result);
    }

    public function testGetMultipleDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('getMultiple')
            ->with(['test_prefix:key1', 'test_prefix:key2'], 'default')
            ->willReturn(['test_prefix:key1' => 'value1', 'test_prefix:key2' => 'value2']);

        $result = $this->bridge->getMultiple(['key1', 'key2'], 'default');
        $this->assertEquals(['test_prefix:key1' => 'value1', 'test_prefix:key2' => 'value2'], $result);
    }

    public function testSetMultipleDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('setMultiple')
            ->with(['test_prefix:key1' => 'value1', 'test_prefix:key2' => 'value2'], 3600)
            ->willReturn(true);

        $result = $this->bridge->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 3600);
        $this->assertTrue($result);
    }

    public function testDeleteMultipleDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('deleteMultiple')
            ->with(['test_prefix:key1', 'test_prefix:key2'])
            ->willReturn(true);

        $result = $this->bridge->deleteMultiple(['key1', 'key2']);
        $this->assertTrue($result);
    }

    public function testHasDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('has')
            ->with('test_prefix:key')
            ->willReturn(true);

        $result = $this->bridge->has('key');
        $this->assertTrue($result);
    }

    public function testRememberDelegatesToCacheManagerWithPrefix(): void
    {
        $callback = fn() => 'value';
        $this->cacheManager->expects($this->once())
            ->method('remember')
            ->with('test_prefix:key', 3600, $callback)
            ->willReturn('value');

        $result = $this->bridge->remember('key', 3600, $callback);
        $this->assertEquals('value', $result);
    }

    public function testForeverDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('forever')
            ->with('test_prefix:key', 'value')
            ->willReturn(true);

        $result = $this->bridge->forever('key', 'value');
        $this->assertTrue($result);
    }

    public function testIncrementDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('increment')
            ->with('test_prefix:key', 1)
            ->willReturn(2);

        $result = $this->bridge->increment('key');
        $this->assertEquals(2, $result);
    }

    public function testDecrementDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('decrement')
            ->with('test_prefix:key', 1)
            ->willReturn(0);

        $result = $this->bridge->decrement('key');
        $this->assertEquals(0, $result);
    }

    public function testPullDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('pull')
            ->with('test_prefix:key', 'default')
            ->willReturn('value');

        $result = $this->bridge->pull('key', 'default');
        $this->assertEquals('value', $result);
    }

    public function testAddDelegatesToCacheManagerWithPrefix(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('add')
            ->with('test_prefix:key', 'value', 3600)
            ->willReturn(true);

        $result = $this->bridge->add('key', 'value', 3600);
        $this->assertTrue($result);
    }

    public function testTagsDelegatesToCacheManager(): void
    {
        $taggedManager = $this->createMock(\MonkeysLegion\Cache\CacheInterface::class);
        $this->cacheManager->expects($this->once())
            ->method('tags')
            ->with(['tag1', 'tag2'])
            ->willReturn($taggedManager);

        $result = $this->bridge->tags(['tag1', 'tag2']);
        $this->assertSame($taggedManager, $result);
    }

    public function testStoreDelegatesToCacheManager(): void
    {
        $storeManager = $this->createMock(\MonkeysLegion\Cache\CacheInterface::class);
        $this->cacheManager->expects($this->once())
            ->method('store')
            ->with('redis')
            ->willReturn($storeManager);
        
        $result = $this->bridge->store('redis');
        $this->assertSame($storeManager, $result); 
    }

    public function testIsConnectedReturnsTrueWhenCheckSucceeds(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('set')
            ->with('__connection_test__', true, 1);
        
        $this->cacheManager->expects($this->once())
            ->method('has')
            ->with('__connection_test__')
            ->willReturn(true);

        $this->cacheManager->expects($this->once())
            ->method('delete')
            ->with('__connection_test__');

        $this->assertTrue($this->bridge->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenCheckFails(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('set')
            ->willThrowException(new \Exception('Connection failed'));

        $this->assertFalse($this->bridge->isConnected());
    }

    public function testGetCacheManagerReturnsUnderlyingManager(): void
    {
        $result = $this->bridge->getCacheManager();
        $this->assertSame($this->cacheManager, $result);
    }

    public function testGetPrefixReturnsConfiguredPrefix(): void
    {
        $result = $this->bridge->getPrefix();
        $this->assertEquals('test_prefix:', $result);
    }

    public function testGetPrefixReturnsEmptyStringWhenNoPrefixConfigured(): void
    {
        $bridge = new CacheManagerBridge($this->cacheManager);
        $this->assertEquals('', $bridge->getPrefix());
    }

    public function testNoPrefixAppliedWhenPrefixIsEmpty(): void
    {
        $bridge = new CacheManagerBridge($this->cacheManager, '');
        
        $this->cacheManager->expects($this->once())
            ->method('get')
            ->with('key', null)
            ->willReturn('value');

        $result = $bridge->get('key');
        $this->assertEquals('value', $result);
    }

    public function testGetStatisticsWithStoreSupport(): void
    {
        $mockStore = new class {
            public function getStatistics() {
                return ['hits' => 10, 'misses' => 5];
            }
        };
        
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willReturn($mockStore);

        $stats = $this->bridge->getStatistics();
        $this->assertEquals(['hits' => 10, 'misses' => 5], $stats);
    }

    public function testGetStatisticsWithoutStoreSupport(): void
    {
        $mockStore = $this->createMock(\stdClass::class);
        
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willReturn($mockStore);

        $stats = $this->bridge->getStatistics();
        $this->assertArrayHasKey('driver', $stats);
        $this->assertArrayHasKey('prefix', $stats);
        $this->assertArrayHasKey('connected', $stats);
        $this->assertEquals('test_prefix:', $stats['prefix']);
    }

    public function testGetStatisticsReturnsErrorOnException(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willThrowException(new \Exception('Store error'));

        $stats = $this->bridge->getStatistics();
        $this->assertArrayHasKey('error', $stats);
        $this->assertArrayHasKey('connected', $stats);
        $this->assertFalse($stats['connected']);
    }

    public function testClearByPrefixWithStoreSupport(): void
    {
        $mockStore = new class {
            public function clearByPrefix(string $prefix): bool {
                return true;
            }
        };
        
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willReturn($mockStore);

        $result = $this->bridge->clearByPrefix('user_');
        $this->assertTrue($result);
    }

    public function testClearByPrefixWithTagsSupport(): void
    {
        $mockStore = new class {
            public function tags(array $tags) {
                return true;
            }
        };
        
        $taggedManager = $this->createMock(\MonkeysLegion\Cache\CacheInterface::class);
        $taggedManager->expects($this->once())
            ->method('clear')
            ->willReturn(true);
        
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willReturn($mockStore);
            
        $this->cacheManager->expects($this->once())
            ->method('tags')
            ->with(['test_prefix:user_'])
            ->willReturn($taggedManager);

        $result = $this->bridge->clearByPrefix('user_');
        $this->assertTrue($result);
    }

    public function testClearByPrefixReturnsFalseWhenNotSupported(): void
    {
        $mockStore = $this->createMock(\stdClass::class);
        
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willReturn($mockStore);

        $result = $this->bridge->clearByPrefix('user_');
        $this->assertFalse($result);
    }

    public function testGetThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('get')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to get cache key 'key'");
        $this->bridge->get('key');
    }

    public function testSetThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('set')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to set cache key 'key'");
        $this->bridge->set('key', 'value');
    }

    public function testDeleteThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('delete')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to delete cache key 'key'");
        $this->bridge->delete('key');
    }

    public function testClearThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('clear')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage('Failed to clear cache');
        $this->bridge->clear();
    }

    public function testGetMultipleThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('getMultiple')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage('Failed to get multiple cache keys');
        $this->bridge->getMultiple(['key1', 'key2']);
    }

    public function testSetMultipleThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('setMultiple')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage('Failed to set multiple cache keys');
        $this->bridge->setMultiple(['key1' => 'value1']);
    }

    public function testDeleteMultipleThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('deleteMultiple')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage('Failed to delete multiple cache keys');
        $this->bridge->deleteMultiple(['key1', 'key2']);
    }

    public function testHasThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('has')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to check cache key 'key'");
        $this->bridge->has('key');
    }

    public function testRememberThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('remember')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to remember cache key 'key'");
        $this->bridge->remember('key', 3600, fn() => 'value');
    }

    public function testForeverThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('forever')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to store forever cache key 'key'");
        $this->bridge->forever('key', 'value');
    }

    public function testIncrementThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('increment')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to increment cache key 'key'");
        $this->bridge->increment('key');
    }

    public function testDecrementThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('decrement')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to decrement cache key 'key'");
        $this->bridge->decrement('key');
    }

    public function testPullThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('pull')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to pull cache key 'key'");
        $this->bridge->pull('key');
    }

    public function testAddThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('add')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to add cache key 'key'");
        $this->bridge->add('key', 'value');
    }

    public function testClearByPrefixThrowsCacheExceptionOnFailure(): void
    {
        $this->cacheManager->expects($this->once())
            ->method('getStore')
            ->willThrowException(new \Exception('Storage error'));

        $this->expectException(\MonkeysLegion\Database\Exceptions\CacheException::class);
        $this->expectExceptionMessage("Failed to clear by prefix 'user_'");
        $this->bridge->clearByPrefix('user_');
    }
}

class CacheManagerMock extends CacheManager
{
    public function get($key, $default = null) {}
    public function set($key, $value, $ttl = null) {}
    public function delete($key) {}
    public function clear() {}
    public function getMultiple($keys, $default = null) {}
    public function setMultiple($values, $ttl = null) {}
    public function deleteMultiple($keys) {}
    public function has($key) {}
    public function remember($key, $ttl, $callback) {}
    public function forever($key, $value) {}
    public function increment($key, $value = 1) {}
    public function decrement($key, $value = 1) {}
    public function pull($key, $default = null) {}
    public function add($key, $value, $ttl = null) {}
    public function tags($tags) {}
    public function getStore() {}
}
