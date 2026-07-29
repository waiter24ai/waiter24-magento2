<?php

namespace Waiter24\Export\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Waiter24\Export\Model\Config;
use Waiter24\Export\Model\MenuExporter;

/**
 * Scheduled catalog push. Runs per store that has a token configured **and
 * automatic sync switched on**, so a multi-store Magento instance can feed
 * several Waiter24 tenants — and a store that only ever wanted to press Export
 * Now is never exported behind its owner's back.
 */
class Export
{
    public function __construct(
        private readonly MenuExporter $exporter,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if ($this->config->getImportToken($storeId) === '' || ! $this->config->isAutoSyncEnabled($storeId)) {
                continue;
            }

            try {
                $result = $this->exporter->run($storeId);
                $this->logger->info('Waiter24 scheduled export OK', ['store' => $storeId, 'result' => $result]);
            } catch (\Throwable $e) {
                $this->logger->error('Waiter24 scheduled export failed', ['store' => $storeId, 'error' => $e->getMessage()]);
            }
        }
    }
}
