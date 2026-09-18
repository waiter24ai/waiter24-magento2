<?php

namespace Waiter24\Export\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Debounced "products that changed" queue, filled by the realtime observers
 * and drained by the realtime cron. A generic cache entry rather than a new
 * table — this only ever holds a handful of ids between two cron ticks, and
 * losing it costs nothing more than waiting for the next scheduled export.
 */
class RealtimeQueue
{
    private const CACHE_KEY = 'waiter24_realtime_dirty_products';
    private const CACHE_TAG = 'WAITER24_EXPORT';
    private const LIFETIME = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $json,
    ) {}

    public function push(int $productId): void
    {
        $ids = $this->all();

        if (in_array($productId, $ids, true)) {
            return;
        }

        $ids[] = $productId;
        $this->cache->save($this->json->serialize($ids), self::CACHE_KEY, [self::CACHE_TAG], self::LIFETIME);
    }

    /**
     * Read and clear the queue in one call — flush() always owns whatever it
     * returns, so nothing is processed twice.
     *
     * @return int[]
     */
    public function flush(): array
    {
        $ids = $this->all();
        $this->cache->remove(self::CACHE_KEY);

        return $ids;
    }

    /**
     * @return int[]
     */
    private function all(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);

        if (! $raw) {
            return [];
        }

        try {
            $ids = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return [];
        }

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
}
