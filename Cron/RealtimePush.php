<?php

namespace Waiter24\Export\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Waiter24\Export\Model\Config;
use Waiter24\Export\Model\MenuExporter;
use Waiter24\Export\Model\RealtimeQueue;

/**
 * Flushes the products the realtime observers queued since the last tick —
 * within a minute or two of a price cut, a stock flip, or a new product going
 * live, instead of waiting for the nightly export. That export is unchanged
 * and still the full reconciliation: a product removed without ever touching
 * an observer (a hard delete, a direct DB write) is only caught there.
 *
 * Same per-store gate as the scheduled export: only stores with a token
 * configured **and** automatic sync switched on receive anything.
 */
class RealtimePush
{
    public function __construct(
        private readonly RealtimeQueue $queue,
        private readonly MenuExporter $exporter,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        $productIds = $this->queue->flush();

        if ($productIds === []) {
            return;
        }

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if ($this->config->getImportToken($storeId) === '' || ! $this->config->isAutoSyncEnabled($storeId)) {
                continue;
            }

            try {
                $result = $this->exporter->pushProducts($productIds, $storeId);
                $this->logger->info('Waiter24 realtime push OK', ['store' => $storeId, 'result' => $result]);
            } catch (\Throwable $e) {
                $this->logger->error('Waiter24 realtime push failed', ['store' => $storeId, 'error' => $e->getMessage()]);
            }
        }
    }
}
