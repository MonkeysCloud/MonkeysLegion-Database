<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\InvalidArgumentException;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;

class FileSystemAdapterTest extends TestCase
{
    private FileSystemAdapter $adapter;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
        $this->adapter = new FileSystemAdapter($this->tempDir);
        // Remove the manual mkdir since FileSystemAdapter constructor already creates it
        $this->cleanupTestCache();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestCache();
        if (is_dir($this->tempDir)) {
            rrmdir($this->tempDir);
        }
    }

    /**
     * Clean up test cache files and directories before running test logic
     */
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

    public function testConstructorCreatesDirectory(): void
    {
        $testDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
        $adapter = new FileSystemAdapter($testDir);

        $this->assertTrue(is_dir($testDir));

        // Cleanup
        rrmdir($testDir);
    }

    public function testConstructorThrowsExceptionOnInvalidDirectory(): void
    {
        // Pick a path based on the OS
        $invalidDir = match (PHP_OS_FAMILY) {
            'Windows' => 'C:\\Windows\\System32\\cannot_create_here_' . uniqid(),
            default   => '/root/cannot_create_here_' . uniqid(),
        };

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Cannot create cache directory');

        new FileSystemAdapter($invalidDir);
    }

    public function testGetItemReturnsEmptyItemWhenNotFound(): void
    {
        $item = $this->adapter->getItem('nonexistent');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertEquals('nonexistent', $item->getKey());
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());
    }

    public function testSaveAndGetItem(): void
    {
        $item = new CacheItem('valid_test_key');
        $item->set('test_value');

        $this->assertTrue($this->adapter->save($item));

        $retrievedItem = $this->adapter->getItem('valid_test_key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('test_value', $retrievedItem->get());
    }

    public function testSaveItemWithExpiration(): void
    {
        $item = new CacheItem('valid_expiring_key');
        $item->set('expiring_value');
        $item->expiresAfter(1); // 1 second

        $this->assertTrue($this->adapter->save($item));

        $retrievedItem = $this->adapter->getItem('valid_expiring_key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('expiring_value', $retrievedItem->get());

        // Wait for expiration
        sleep(2);

        $expiredItem = $this->adapter->getItem('valid_expiring_key');
        $this->assertFalse($expiredItem->isHit());
        $this->assertNull($expiredItem->get());
    }

    public function testHasItem(): void
    {
        $this->assertFalse($this->adapter->hasItem('valid_test_key'));

        $item = new CacheItem('valid_test_key');
        $item->set('test_value');
        $this->adapter->save($item);

        $this->assertTrue($this->adapter->hasItem('valid_test_key'));
    }

    public function testHasItemWithExpiredItem(): void
    {
        $item = new CacheItem('valid_expiring_key');
        $item->set('expiring_value');
        $item->expiresAfter(1);
        $this->adapter->save($item);

        $this->assertTrue($this->adapter->hasItem('valid_expiring_key'));

        sleep(2);

        $this->assertFalse($this->adapter->hasItem('valid_expiring_key'));
    }

    public function testDeleteItem(): void
    {
        $item = new CacheItem('valid_test_key');
        $item->set('test_value');
        $this->adapter->save($item);

        $this->assertTrue($this->adapter->hasItem('valid_test_key'));
        $this->assertTrue($this->adapter->deleteItem('valid_test_key'));
        $this->assertFalse($this->adapter->hasItem('valid_test_key'));
    }

    public function testDeleteNonExistentItem(): void
    {
        $this->assertTrue($this->adapter->deleteItem('nonexistent'));
    }

    public function testGetItems(): void
    {
        $item1 = new CacheItem('valid_key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('valid_key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $items = iterator_to_array($this->adapter->getItems(['valid_key1', 'valid_key2', 'valid_key3']));

        $this->assertCount(3, $items);
        $this->assertTrue($items['valid_key1']->isHit());
        $this->assertEquals('value1', $items['valid_key1']->get());
        $this->assertTrue($items['valid_key2']->isHit());
        $this->assertEquals('value2', $items['valid_key2']->get());
        $this->assertFalse($items['valid_key3']->isHit());
    }

    public function testDeleteItems(): void
    {
        $item1 = new CacheItem('valid_key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('valid_key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $this->assertTrue($this->adapter->deleteItems(['valid_key1', 'valid_key2']));

        $this->assertFalse($this->adapter->hasItem('valid_key1'));
        $this->assertFalse($this->adapter->hasItem('valid_key2'));
    }

    public function testClear(): void
    {
        $item1 = new CacheItem('valid_key1');
        $item1->set('value1');
        $this->adapter->save($item1);

        $item2 = new CacheItem('valid_key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        $this->assertTrue($this->adapter->clear());

        $this->assertFalse($this->adapter->hasItem('valid_key1'));
        $this->assertFalse($this->adapter->hasItem('valid_key2'));
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
        $item = new CacheItem('valid_deferred_key');
        $item->set('deferred_value');

        $this->assertTrue($this->adapter->saveDeferred($item));
        $this->assertFalse($this->adapter->hasItem('valid_deferred_key'));

        $this->assertTrue($this->adapter->commit());
        $this->assertTrue($this->adapter->hasItem('valid_deferred_key'));
    }

    public function testGetStatistics(): void
    {
        // Initially empty
        $stats = $this->adapter->getStatistics();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['writes']);
        $this->assertEquals(0, $stats['deletes']);
        $this->assertEquals(0.0, $stats['hit_ratio']);

        // Save an item
        $item = new CacheItem('valid_test_key');
        $item->set('test_value');
        $this->adapter->save($item);

        // Hit
        $this->adapter->getItem('valid_test_key');

        // Miss
        $this->adapter->getItem('valid_nonexistent');

        $stats = $this->adapter->getStatistics();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(1, $stats['writes']);
        $this->assertEquals(50.0, $stats['hit_ratio']);
        $this->assertArrayHasKey('cache_files', $stats);
        $this->assertArrayHasKey('lock_files', $stats);
        $this->assertArrayHasKey('cache_size_bytes', $stats);
    }

    public function testResetStatistics(): void
    {
        // Generate some statistics
        $item = new CacheItem('valid_test_key');
        $item->set('test_value');
        $this->adapter->save($item);
        $this->adapter->getItem('valid_test_key');

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

    public function testLockExpiration(): void
    {
        $this->adapter->setLockExpiration(1); // 1 second

        // This is mainly to test that the method exists and doesn't throw
        $item = new CacheItem('test_key');
        $item->set('test_value');
        $this->assertTrue($this->adapter->save($item));
    }

    public function testCleanupExpiredLocks(): void
    {
        // This method mainly tests internal lock cleanup
        $cleaned = $this->adapter->cleanupExpiredLocks();
        $this->assertGreaterThanOrEqual(0, $cleaned);
    }

    public function testRunFullCleanup(): void
    {
        // Add some cache items
        $item1 = new CacheItem('key1');
        $item1->set('value1');
        $item1->expiresAfter(1);
        $this->adapter->save($item1);

        $item2 = new CacheItem('key2');
        $item2->set('value2');
        $this->adapter->save($item2);

        // Wait for one to expire
        sleep(2);

        $stats = $this->adapter->runFullCleanup();

        $this->assertArrayHasKey('expired_cache_files', $stats);
        $this->assertArrayHasKey('expired_lock_files', $stats);
        $this->assertArrayHasKey('corrupted_files', $stats);
        $this->assertArrayHasKey('total_freed_bytes', $stats);
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->getItem('invalid/key');
    }

    public function testSaveWithPsrCacheItemInterface(): void
    {
        // Mock PSR CacheItemInterface
        $mockItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockItem->method('getKey')->willReturn('valid_psr_key');
        $mockItem->method('get')->willReturn('psr_value');

        $this->assertTrue($this->adapter->save($mockItem));
        $this->assertTrue($this->adapter->hasItem('valid_psr_key'));
    }

    public function testFileSystemErrors(): void
    {
        // Test that errors don't crash the application
        $item = new CacheItem('valid_error_test');
        $item->set('error_value');

        // This should work normally
        $this->assertTrue($this->adapter->save($item));

        $retrievedItem = $this->adapter->getItem('valid_error_test');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('error_value', $retrievedItem->get());
    }
}
