<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Adapters;

use MonkeysLegion\Database\Cache\Adapters\ArrayCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ArrayCacheAdapterTest extends TestCase
{
    private ArrayCacheAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ArrayCacheAdapter();
    }

    protected function tearDown(): void
    {
        $this->adapter->clear();
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

    public function testCommitMultipleDeferred(): void
    {
        $item1 = new CacheItem('valid_deferred1');
        $item1->set('value1');
        $this->adapter->saveDeferred($item1);

        $item2 = new CacheItem('valid_deferred2');
        $item2->set('value2');
        $this->adapter->saveDeferred($item2);

        $this->assertFalse($this->adapter->hasItem('valid_deferred1'));
        $this->assertFalse($this->adapter->hasItem('valid_deferred2'));

        $this->assertTrue($this->adapter->commit());

        $this->assertTrue($this->adapter->hasItem('valid_deferred1'));
        $this->assertTrue($this->adapter->hasItem('valid_deferred2'));
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

        // Delete
        $this->adapter->deleteItem('valid_test_key');

        $stats = $this->adapter->getStatistics();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(1, $stats['writes']);
        $this->assertEquals(1, $stats['deletes']);
        $this->assertEquals(50.0, $stats['hit_ratio']);
        $this->assertEquals(2, $stats['total_operations']);
        $this->assertEquals(0, $stats['cache_items']);
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

    public function testCacheItemTypes(): void
    {
        // Test different data types
        $testData = [
            'valid_string' => 'test_string',
            'valid_integer' => 42,
            'valid_float' => 3.14,
            'valid_boolean' => true,
            'valid_array' => ['key' => 'value'],
            'valid_object' => (object)['property' => 'value'],
            'valid_null' => null,
        ];

        foreach ($testData as $key => $value) {
            $item = new CacheItem($key);
            $item->set($value);
            $this->adapter->save($item);

            $retrievedItem = $this->adapter->getItem($key);
            $this->assertTrue($retrievedItem->isHit());
            $this->assertEquals($value, $retrievedItem->get());
        }
    }
}
