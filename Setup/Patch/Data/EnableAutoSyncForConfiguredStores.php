<?php

namespace Waiter24\Export\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Carry a working daily sync across the 1.2.0 upgrade.
 *
 * Before 1.2.0 the cron exported any store that had an import token, with no
 * way to say no. `waiter24_export/export/auto_sync` now gates it and defaults
 * to off — which, without this patch, would silently stop the nightly push for
 * every store already relying on it.
 *
 * So: every scope that has a token configured is switched **on explicitly**,
 * and the new default is left to do its job on stores that are not set up yet.
 * A store that is later switched off stays off — the patch runs once.
 */
class EnableAutoSyncForConfiguredStores implements DataPatchInterface
{
    private const PATH_TOKEN     = 'waiter24_export/general/import_token';
    private const PATH_AUTO_SYNC = 'waiter24_export/export/auto_sync';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
    ) {}

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('core_config_data');

        // Scopes with a token saved — the ones the old cron would have exported.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['scope', 'scope_id'])
                ->where('path = ?', self::PATH_TOKEN)
                ->where('value IS NOT NULL')
                ->where('value != ?', '')
        );

        foreach ($rows as $row) {
            $scope   = (string) $row['scope'];
            $scopeId = (int) $row['scope_id'];

            // Respect an explicit choice, in the unlikely case one already exists.
            $existing = $connection->fetchOne(
                $connection->select()
                    ->from($table, 'value')
                    ->where('path = ?', self::PATH_AUTO_SYNC)
                    ->where('scope = ?', $scope)
                    ->where('scope_id = ?', $scopeId)
            );

            if ($existing !== false) {
                continue;
            }

            $this->configWriter->save(self::PATH_AUTO_SYNC, '1', $scope, $scopeId);
        }

        return $this;
    }
}
