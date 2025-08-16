<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Adapters;

use MonkeysLegion\Database\Cache\Contracts\CacheItemPoolInterface;
use MonkeysLegion\Database\Cache\Enum\Constants;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use MonkeysLegion\Database\Cache\Utils\CacheKeyValidator;

class FileSystemAdapter implements CacheItemPoolInterface
{
    private string $directory;
    /** @var array<string, \Psr\Cache\CacheItemInterface> */
    private array $deferred = [];
    private int $lockExpiration = Constants::CACHE_LOCK_EXPIRATION;

    // Cache statistics
    /** @var array<string, int> */
    private array $statistics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
        'errors' => 0,
        'lock_waits' => 0,
        'lock_timeouts' => 0,
        'stale_returns' => 0
    ];

    // Auto-cleanup settings
    private bool $autoCleanup = true;
    private int $cleanupProbability = 100; // 1 in 100 chance per operation
    private int $lastCleanup = 0;
    private int $cleanupInterval = 3600; // 1 hour

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory . '/var/cache' ?? sys_get_temp_dir() . '/cache';

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true)) {
            throw new CacheException("Cannot create cache directory: {$this->directory}");
        }
    }

    public function getItem(string $key): CacheItem
    {
        CacheKeyValidator::validateKey($key);

        $item = new CacheItem($key);
        $filename = $this->getFilename($key);
        $lockFile = $this->getLockFilename($key);

        try {
            if (file_exists($filename)) {
                $content = $this->safeFileRead($filename);
                if ($content === false) {
                    $this->statistics['errors']++;
                    return $item;
                }

                $data = @unserialize($content);
                if ($data === false) {
                    $this->statistics['errors']++;
                    $this->safeFileDelete($filename);
                    return $item;
                }

                if ($data['expiration'] === null || $data['expiration'] > time()) {
                    $item->set($data['value']);
                    $item->setHit(true);
                    if ($data['expiration'] !== null) {
                        $item->expiresAt((new \DateTimeImmutable())->setTimestamp($data['expiration']));
                    }
                    $this->statistics['hits']++;
                    return $item;
                }

                if ($this->isLockValid($lockFile)) {
                    $lockData = @unserialize(@file_get_contents($lockFile) ?: '') ?: [];

                    if (!isset($lockData['process_id']) || $lockData['process_id'] !== getmypid()) {
                        $this->statistics['stale_returns']++;
                        $item->set($data['value']);
                        $item->setHit(false);
                        return $item;
                    }
                }

                $this->safeFileDelete($filename);
            }

            $this->statistics['misses']++;
        } catch (\Throwable $e) {
            $this->statistics['errors']++;
        }
        $this->maybeRunCleanup();

        return $item;
    }

    /**
     * Get multiple cache items.
     *
     * @param array<string> $keys
     * @return iterable<string, \Psr\Cache\CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            CacheKeyValidator::validateKey($key);
        }

        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    public function hasItem(string $key): bool
    {
        CacheKeyValidator::validateKey($key);

        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return false;
        }

        $data = unserialize(file_get_contents($filename) ?: '');

        if ($data['expiration'] !== null && $data['expiration'] <= time()) {
            unlink($filename);
            return false;
        }

        return true;
    }

    public function clear(): bool
    {
        /** @var array<string> $files */
        $files = glob($this->directory . '/*.cache') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }

    public function deleteItem(string $key): bool
    {
        CacheKeyValidator::validateKey($key);

        $filename = $this->getFilename($key);

        try {
            if (file_exists($filename)) {
                $result = $this->safeFileDelete($filename);
                if ($result) {
                    $this->statistics['deletes']++;
                } else {
                    $this->statistics['errors']++;
                }
                return $result;
            }
            return true;
        } catch (\Throwable $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    /**
     * Delete multiple cache items.
     *
     * @param array<string> $keys
     * @return bool
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            CacheKeyValidator::validateKey($key);
        }

        foreach ($keys as $key) {
            $this->deleteItem($key);
        }
        return true;
    }

    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        try {
            $filename = $this->getFilename($item->getKey());
            $lockFile = $this->getLockFilename($item->getKey());

            // Create lock file with timestamp to prevent concurrent access
            if (!$this->createLockFile($lockFile)) {
                $this->statistics['errors']++;
                return false;
            }

            $lockHandle = fopen($lockFile, 'r+');
            if (!$lockHandle) {
                $this->statistics['errors']++;
                return false;
            }

            if (flock($lockHandle, LOCK_EX)) {
                try {
                    $expiration = null;

                    if ($item instanceof CacheItem && $item->getExpiration()) {
                        $expiration = $item->getExpiration()->getTimestamp();
                    } elseif ($item->get() !== null) {
                        $expiration = time() + Constants::CACHE_DEFAULT_TTL;
                    }

                    $data = [
                        'value' => $item->get(),
                        'expiration' => $expiration
                    ];

                    $serialized = serialize($data);
                    $result = $this->safeFileWrite($filename, $serialized);

                    if ($result) {
                        $this->statistics['writes']++;
                    } else {
                        $this->statistics['errors']++;
                    }

                    return $result;
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                    $this->safeFileDelete($lockFile); // Clean up lock file
                }
            }

            fclose($lockHandle);
            $this->statistics['errors']++;
            return false;
        } catch (\Throwable $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];
        return true;
    }

    /**
     * Get cache item filename.
     *
     * @param string $key Cache item key
     * @return string Full path to cache item file
     */
    public function getFilename(string $key): string
    {
        $prefix = $this->extractPrefix($key);

        return $this->directory
            . '/'
            . hash('sha256', $prefix)    // for prefix grouping
            . '_'
            . hash('sha256', $key)       // for uniqueness
            . Constants::CACHE_ITEM_SUFFIX;
    }

    /**
     * Get lock file name for a cache item.
     *
     * @param string $key Cache item key
     * @return string Full path to lock file
     */
    public function getLockFilename(string $key): string
    {
        $prefix = $this->extractPrefix($key);

        return $this->directory
            . '/'
            . hash('sha256', $prefix)    // match data filename's prefix hash
            . '_'
            . hash('sha256', $key)       // match data filename's key hash
            . Constants::CACHE_LOCK_SUFFIX;
    }

    private function extractPrefix(string $key): string
    {
        if (preg_match('/^(.*?)[_\-\.]/', $key, $matches)) {
            return $matches[1];
        }

        return $key;
    }

    /**
     * Create lock file with timestamp for expiration tracking.
     *
     * @param string $lockFile Path to lock file
     * @return bool True if lock created successfully
     */
    private function createLockFile(string $lockFile): bool
    {
        $lockData = [
            'created_at' => time(),
            'expires_at' => time() + $this->lockExpiration,
            'process_id' => getmypid()
        ];

        return file_put_contents($lockFile, serialize($lockData), LOCK_EX) !== false;
    }

    /**
     * Check if lock file exists and is still valid (not expired).
     *
     * @param string $lockFile Path to lock file
     * @return bool True if lock is valid and not expired
     */
    private function isLockValid(string $lockFile): bool
    {
        if (!file_exists($lockFile)) {
            return false;
        }

        $content = file_get_contents($lockFile);
        if ($content === false) {
            return false;
        }

        $lockData = unserialize($content);
        if (!is_array($lockData) || !isset($lockData['expires_at'])) {
            // Invalid lock file format, clean it up
            @unlink($lockFile);
            return false;
        }

        // Check if lock has expired
        if (time() > $lockData['expires_at']) {
            @unlink($lockFile); // Clean up expired lock
            return false;
        }

        return true;
    }

    public function clearByPrefix(string $prefix): bool
    {
        /** @var array<string> $files */
        $files = glob($this->directory . '/*' . Constants::CACHE_ITEM_SUFFIX) ?: [];
        $cleared = false;

        // Extract prefix from the given search term
        $prefixMatch = hash('sha256', $this->extractPrefix($prefix));

        foreach ($files as $file) {
            $filename = basename($file, Constants::CACHE_ITEM_SUFFIX);

            if (str_starts_with($filename, $prefixMatch)) {
                unlink($file);
                $cleared = true;
            }
        }

        return $cleared;
    }

    /**
     * Set lock expiration time.
     *
     * @param int $seconds Seconds before lock expires
     * @return void
     */
    public function setLockExpiration(int $seconds): void
    {
        $this->lockExpiration = $seconds;
    }

    /**
     * Clean up expired lock files.
     *
     * @return int Number of locks cleaned up
     */
    public function cleanupExpiredLocks(): int
    {
        $lockFiles = glob($this->directory . '/*' . Constants::CACHE_LOCK_SUFFIX);
        if ($lockFiles === false) return 0;

        $cleaned = 0;
        foreach ($lockFiles as $lockFile) {
            if (!$this->isLockValid($lockFile)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Safe file read with error handling.
     *
     * @param string $filename
     * @return string|false
     */
    private function safeFileRead(string $filename): string|false
    {
        try {
            $content = @file_get_contents($filename);
            return $content !== false ? $content : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Safe file write with error handling.
     *
     * @param string $filename
     * @param string $content
     * @return bool
     */
    private function safeFileWrite(string $filename, string $content): bool
    {
        try {
            return @file_put_contents($filename, $content, LOCK_EX) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Safe file delete with error handling.
     *
     * @param string $filename
     * @return bool
     */
    private function safeFileDelete(string $filename): bool
    {
        try {
            return !file_exists($filename) || @unlink($filename);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return $this->statistics + [
            'hit_ratio' => $this->calculateHitRatio(),
            'total_operations' => $this->statistics['hits'] + $this->statistics['misses'],
            'cache_files' => $this->countCacheFiles(),
            'lock_files' => $this->countLockFiles(),
            'cache_size_bytes' => $this->calculateCacheSize(),
        ];
    }

    /**
     * Reset cache statistics.
     */
    public function resetStatistics(): void
    {
        $this->statistics = array_fill_keys(array_keys($this->statistics), 0);
    }

    /**
     * Calculate hit ratio as percentage.
     *
     * @return float
     */
    private function calculateHitRatio(): float
    {
        $total = $this->statistics['hits'] + $this->statistics['misses'];
        return $total > 0 ? ($this->statistics['hits'] / $total) * 100 : 0.0;
    }

    /**
     * Count cache files.
     *
     * @return int
     */
    private function countCacheFiles(): int
    {
        $files = glob($this->directory . '/*' . Constants::CACHE_ITEM_SUFFIX);
        return $files !== false ? count($files) : 0;
    }

    /**
     * Count lock files.
     *
     * @return int
     */
    private function countLockFiles(): int
    {
        $files = glob($this->directory . '/*' . Constants::CACHE_LOCK_SUFFIX);
        return $files !== false ? count($files) : 0;
    }

    /**
     * Calculate total cache size in bytes.
     *
     * @return int
     */
    private function calculateCacheSize(): int
    {
        $files = glob($this->directory . '/*' . Constants::CACHE_ITEM_SUFFIX);
        if ($files === false) return 0;

        $totalSize = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            if ($size !== false) {
                $totalSize += $size;
            }
        }
        return $totalSize;
    }

    /**
     * Maybe run cleanup based on probability.
     */
    private function maybeRunCleanup(): void
    {
        if (!$this->autoCleanup) {
            return;
        }

        // Time-based cleanup
        if (time() - $this->lastCleanup > $this->cleanupInterval) {
            $this->runFullCleanup();
            return;
        }

        // Probability-based cleanup
        if (mt_rand(1, $this->cleanupProbability) === 1) {
            $this->cleanupExpiredLocks();
        }
    }

    /**
     * Run full cleanup of expired cache and lock files.
     *
     * @return array<string, int> Cleanup statistics
     */
    public function runFullCleanup(): array
    {
        $stats = [
            'expired_cache_files' => 0,
            'expired_lock_files' => 0,
            'corrupted_files' => 0,
            'total_freed_bytes' => 0
        ];

        // Clean up expired cache files
        $cacheFiles = glob($this->directory . '/*' . Constants::CACHE_ITEM_SUFFIX);
        if ($cacheFiles !== false) {
            foreach ($cacheFiles as $file) {
                $size = @filesize($file);
                $content = $this->safeFileRead($file);

                if ($content === false) {
                    // Corrupted file
                    if ($this->safeFileDelete($file)) {
                        $stats['corrupted_files']++;
                        $stats['total_freed_bytes'] += $size ?: 0;
                    }
                    continue;
                }

                $data = @unserialize($content);
                if ($data === false) {
                    // Corrupted data
                    if ($this->safeFileDelete($file)) {
                        $stats['corrupted_files']++;
                        $stats['total_freed_bytes'] += $size ?: 0;
                    }
                    continue;
                }

                // Check if expired
                if ($data['expiration'] !== null && $data['expiration'] <= time()) {
                    if ($this->safeFileDelete($file)) {
                        $stats['expired_cache_files']++;
                        $stats['total_freed_bytes'] += $size ?: 0;
                    }
                }
            }
        }

        // Clean up expired lock files
        $stats['expired_lock_files'] = $this->cleanupExpiredLocks();

        $this->lastCleanup = time();
        return $stats;
    }

    /**
     * Configure auto-cleanup settings.
     *
     * @param bool $enabled
     * @param int $probability 1 in N chance per operation
     * @param int $interval Seconds between full cleanups
     */
    public function configureAutoCleanup(bool $enabled = true, int $probability = 100, int $interval = 3600): void
    {
        $this->autoCleanup = $enabled;
        $this->cleanupProbability = max(1, $probability);
        $this->cleanupInterval = max(60, $interval);
    }

    /**
     * Get the cache directory path.
     */
    public function getCacheDir(): string
    {
        return $this->directory;
    }
}
