<?php

namespace Waiter24\Export\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Waiter24\Export\Model\Config;

/**
 * Frontend block that renders the Waiter24 chat widget <script> tag.
 */
class Widget extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isWidgetEnabled() && $this->getWidgetKey() !== '';
    }

    public function getWidgetKey(): string
    {
        return $this->config->getWidgetKey();
    }

    public function getWidgetUrl(): string
    {
        return $this->config->getWidgetUrl();
    }
}
