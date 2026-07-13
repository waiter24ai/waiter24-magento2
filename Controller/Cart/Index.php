<?php

namespace Waiter24\Export\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Waiter24\Export\Model\Config;

/**
 * Waiter24 cart-bridge: GET /waiter24/cart
 *
 * Returns the visitor's current cart in the platform-neutral shape the chat
 * widget's cart-aware upsell expects: {"items": [{"name", "qty", "price"}]}.
 * Read-only and session-scoped — a visitor only ever sees their own quote.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config,
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (! $this->config->isWidgetEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['items' => []]);
        }

        $items = [];
        try {
            foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
                $items[] = [
                    'name'  => (string) $item->getName(),
                    'qty'   => (int) $item->getQty(),
                    'price' => (float) ($item->getPriceInclTax() ?: $item->getPrice()),
                ];
            }
        } catch (\Throwable) {
            // An unreadable quote must not break the chat — report an empty cart.
        }

        return $result->setData(['items' => $items]);
    }
}
