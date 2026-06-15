<?php

namespace Waiter24\Export\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessor for the module's store-scoped configuration.
 */
class Config
{
    private const XML_PATH = 'waiter24_export/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {}

    private function value(string $path, ?int $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getImportToken(?int $storeId = null): string
    {
        $raw = $this->value('general/import_token', $storeId);

        return $raw ? trim($this->encryptor->decrypt($raw)) : '';
    }

    public function getWidgetKey(?int $storeId = null): string
    {
        return (string) $this->value('general/widget_key', $storeId);
    }

    public function getEndpointUrl(?int $storeId = null): string
    {
        return (string) $this->value('general/endpoint_url', $storeId);
    }

    public function getWidgetUrl(?int $storeId = null): string
    {
        return (string) $this->value('general/widget_url', $storeId);
    }

    public function isSimpleStockMode(?int $storeId = null): bool
    {
        return $this->flag('export/simple_stock_mode', $storeId);
    }

    public function isWidgetEnabled(?int $storeId = null): bool
    {
        return $this->flag('widget/enabled', $storeId);
    }
}
