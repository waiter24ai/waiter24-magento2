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

    /**
     * Demo mode is enforced here, on the server: the script tag is simply not
     * rendered unless the visitor arrived on a demo link, so regular shoppers
     * never receive the widget at all. Hiding it in the browser instead — which
     * is what this used to do — is defeated by anything that touches the markup
     * on the way out, from a full-page cache warmed before the toggle was
     * flipped to a JS optimizer that strips data attributes.
     *
     * Safe under FPC/Varnish: the demo URL carries a query string, and Magento
     * keys its page cache on the full URI, so the demo page and the plain page
     * are different cache entries.
     */
    public function isEnabled(): bool
    {
        if (! $this->config->isWidgetEnabled() || $this->getWidgetKey() === '') {
            return false;
        }

        return ! $this->isDemoMode() || $this->getRequest()->getParam($this->getDemoParam()) !== null;
    }

    public function getWidgetKey(): string
    {
        return $this->config->getWidgetKey();
    }

    public function getWidgetUrl(): string
    {
        return $this->config->getWidgetUrl();
    }

    public function isDemoMode(): bool
    {
        return $this->config->isDemoMode();
    }

    /**
     * GET parameter that reveals the widget when demo mode is on.
     */
    public function getDemoParam(): string
    {
        return 'waiter24_demo';
    }
}
