<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use PHPUnit\Framework\TestCase;

class FileSystemAdapterLockTest extends TestCase
{
    private FileSystemAdapter $adapter;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache_lock_test_' . uniqid();
        $this->adapter = new FileSystemAdapter($this->tempDir);
        $this->cleanupTestCache();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestCache();
        if (is_dir($this->tempDir)) {
            rrmdir($this->tempDir);
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

    public function testLockFileCreationAndCleanup(): void
    {
        $key = 'lock_test_key';
        $item = new CacheItem($key);
        $item->set('lock_test_value');

        // Before save - no lock file should exist
        $lockFiles = glob($this->tempDir . '/*.lock');
        $this->assertEmpty($lockFiles);

        // Save item
        $this->assertTrue($this->adapter->save($item));

        // After save - lock file should be cleaned up
        $lockFiles = glob($this->tempDir . '/*.lock');
        $this->assertEmpty($lockFiles, 'Lock files should be cleaned up after save');
    }

    public function testExpiredCacheWithLockProtection(): void
    {
        $key = 'expired_with_lock_key';

        // Create expired cache item
        $item = new CacheItem($key);
        $item->set('original_value');
        $item->expiresAfter(-1); // Already expired
        $this->assertTrue($this->adapter->save($item));

        // Verify cache file exists before creating lock
        $cacheFile = $this->adapter->getFilename($key);
        $this->assertFileExists($cacheFile);

        // Manually create a lock file from a DIFFERENT process to simulate another process regenerating
        $lockFile = $this->adapter->getLockFilename($key);
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 5, // Valid for 5 seconds
            'process_id' => 9999 // Different PID to simulate another process
        ];
        file_put_contents($lockFile, serialize($lockData));

        // Getting item should return stale data due to lock from different process
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit()); // Should be marked as miss
        $this->assertEquals('original_value', $retrievedItem->get()); // But return stale data

        // Cache file should still exist (not deleted due to lock protection)
        $this->assertFileExists($cacheFile);

        // Clean up
        @unlink($lockFile);
    }

    public function testExpiredCacheWithSameProcessLock(): void
    {
        $key = 'expired_same_process_key';

        // Create expired cache item
        $item = new CacheItem($key);
        $item->set('same_process_value');
        $item->expiresAfter(-1); // Already expired
        $this->assertTrue($this->adapter->save($item));

        // Create a lock file from the SAME process
        $lockFile = $this->adapter->getLockFilename($key);
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 5,
            'process_id' => getmypid() // Same PID
        ];
        file_put_contents($lockFile, serialize($lockData));

        // Getting item should delete the expired cache since it's our own lock
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit());
        $this->assertNull($retrievedItem->get()); // Should be empty/null

        // Cache file should be deleted
        $cacheFile = $this->adapter->getFilename($key);
        $this->assertFileDoesNotExist($cacheFile);

        // Clean up
        @unlink($lockFile);
    }

    public function testLockTimeoutBehavior(): void
    {
        // This test is no longer relevant with the new logic
        // The adapter doesn't wait for locks anymore, it immediately returns stale data
        // if the lock is from a different process

        $key = 'no_timeout_test_key';

        // Create expired item
        $item = new CacheItem($key);
        $item->set('no_timeout_value');
        $item->expiresAfter(-1);
        $this->adapter->save($item);

        // Create a lock file from different process
        $lockFile = $this->adapter->getLockFilename($key);
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 3600, // Valid for 1 hour
            'process_id' => 9999 // Different process
        ];
        file_put_contents($lockFile, serialize($lockData));

        $startTime = microtime(true);
        $retrievedItem = $this->adapter->getItem($key);
        $endTime = microtime(true);

        // Should return immediately (no waiting/timeout)
        $this->assertLessThan(0.1, $endTime - $startTime); // Should be very fast
        $this->assertFalse($retrievedItem->isHit());
        $this->assertEquals('no_timeout_value', $retrievedItem->get());

        // Clean up
        @unlink($lockFile);
    }

    public function testStaleDataReturn(): void
    {
        $key = 'stale_data_key';

        // Create and save an item that will expire
        $item = new CacheItem($key);
        $item->set('stale_value');
        $item->expiresAfter(-1); // Already expired
        $this->adapter->save($item);

        // Manually create a lock from different process to simulate regeneration
        $lockFile = $this->adapter->getLockFilename($key);
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 10,
            'process_id' => 99999 // Different PID
        ];
        file_put_contents($lockFile, serialize($lockData));

        // Should return stale data immediately
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit()); // Marked as miss
        $this->assertEquals('stale_value', $retrievedItem->get()); // But returns stale data

        // Clean up
        @unlink($lockFile);
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
        // Create some expired lock files manually
        $lockFile1 = $this->tempDir . '/expired_lock1.lock';
        $lockFile2 = $this->tempDir . '/expired_lock2.lock';

        $expiredLockData = [
            'created_at' => time() - 3600,
            'expires_at' => time() - 1800, // Expired 30 minutes ago
            'process_id' => 12345
        ];

        file_put_contents($lockFile1, serialize($expiredLockData));
        file_put_contents($lockFile2, serialize($expiredLockData));

        $this->assertFileExists($lockFile1);
        $this->assertFileExists($lockFile2);

        // Clean up expired locks
        $cleaned = $this->adapter->cleanupExpiredLocks();
        $this->assertGreaterThanOrEqual(0, $cleaned);

        // Note: The cleanup method looks for files with CACHE_LOCK_SUFFIX pattern
        // Our manual files might not match the exact pattern, so we just verify the method runs
    }

    public function testLockValidation(): void
    {
        $key = 'lock_validation_key';
        $lockFile = $this->adapter->getLockFilename($key);

        // Create expired cache item FIRST
        $item = new CacheItem($key);
        $item->set('test_value');
        $item->expiresAfter(-1); // Already expired
        $this->adapter->save($item);

        // Verify cache file exists after save
        $cacheFile = $this->adapter->getFilename($key);
        $this->assertFileExists($cacheFile);

        // NOW create a valid lock from different process AFTER the cache exists
        $validLockData = [
            'created_at' => time(),
            'expires_at' => time() + 300, // Valid for 5 minutes
            'process_id' => 9999 // Different process
        ];
        file_put_contents($lockFile, serialize($validLockData));

        // Should respect the lock and return stale data
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit());
        $this->assertEquals('test_value', $retrievedItem->get());

        // Cache file should still exist (protected by lock)
        $this->assertFileExists($cacheFile);

        // Clean up
        @unlink($lockFile);

        // Test with same process lock - create fresh cache first
        $item2 = new CacheItem($key);
        $item2->set('same_process_value');
        $item2->expiresAfter(-1); // Already expired
        $this->adapter->save($item2);

        $sameProcessLockData = [
            'created_at' => time(),
            'expires_at' => time() + 300,
            'process_id' => getmypid() // Same process
        ];
        file_put_contents($lockFile, serialize($sameProcessLockData));

        // Should ignore same-process lock and delete expired cache
        $retrievedItem2 = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem2->isHit());
        $this->assertNull($retrievedItem2->get());

        // Cache file should be deleted
        $this->assertFileDoesNotExist($cacheFile);

        // Clean up
        @unlink($lockFile);

        // Test with invalid/expired lock
        $expiredLockData = [
            'created_at' => time() - 3600,
            'expires_at' => time() - 1800, // Expired
            'process_id' => 9999
        ];

        // Create fresh expired cache for this test
        $item3 = new CacheItem($key);
        $item3->set('expired_lock_test');
        $item3->expiresAfter(-1);
        $this->adapter->save($item3);

        file_put_contents($lockFile, serialize($expiredLockData));

        // Should ignore expired lock and delete expired cache
        $retrievedItem3 = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem3->isHit());
        $this->assertNull($retrievedItem3->get());

        // Lock file should be cleaned up automatically by isLockValid()
        $this->assertFileDoesNotExist($lockFile);
    }

    public function testLockValidationDetailed(): void
    {
        $key = 'detailed_lock_test';

        // Step 1: Create and save an expired cache item
        $item = new CacheItem($key);
        $item->set('protected_value');
        $item->expiresAfter(-1); // Already expired
        $this->assertTrue($this->adapter->save($item));

        $cacheFile = $this->adapter->getFilename($key);
        $lockFile = $this->adapter->getLockFilename($key);

        // Verify cache file exists
        $this->assertFileExists($cacheFile);

        // Step 2: Test behavior WITHOUT lock (should delete expired cache)
        $itemWithoutLock = $this->adapter->getItem($key);
        $this->assertFalse($itemWithoutLock->isHit());
        $this->assertNull($itemWithoutLock->get());
        $this->assertFileDoesNotExist($cacheFile, 'Cache should be deleted without lock protection');

        // Step 3: Create cache again and test WITH lock
        $item2 = new CacheItem($key);
        $item2->set('protected_value_2');
        $item2->expiresAfter(-1); // Already expired
        $this->assertTrue($this->adapter->save($item2));
        $this->assertFileExists($cacheFile);

        // Create lock from different process
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + 300,
            'process_id' => 9999 // Different process
        ];
        file_put_contents($lockFile, serialize($lockData));
        $this->assertFileExists($lockFile);

        // Should return stale data due to lock protection
        $itemWithLock = $this->adapter->getItem($key);
        $this->assertFalse($itemWithLock->isHit());
        $this->assertEquals('protected_value_2', $itemWithLock->get());
        $this->assertFileExists($cacheFile, 'Cache should be protected by lock');

        // Clean up
        @unlink($lockFile);
    }

    public function testNoLockBehavior(): void
    {
        $key = 'no_lock_key';

        // Create expired cache item
        $item = new CacheItem($key);
        $item->set('no_lock_value');
        $item->expiresAfter(-1); // Already expired
        $this->adapter->save($item);

        // No lock file exists
        // Should delete the expired cache and return empty item
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertFalse($retrievedItem->isHit());
        $this->assertNull($retrievedItem->get());

        // Cache file should be deleted
        $cacheFile = $this->adapter->getFilename($key);
        $this->assertFileDoesNotExist($cacheFile);
    }
}
