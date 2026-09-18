<?php

namespace Waiter24\Export\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Waiter24\Export\Model\RealtimeQueue;

/**
 * Stock changes fire through a separate event with a different payload shape
 * than a product save, so they get their own observer — same debounced queue.
 */
class MarkStockItemDirty implements ObserverInterface
{
    public function __construct(private readonly RealtimeQueue $queue) {}

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getItem();
        $productId = $item ? (int) $item->getProductId() : 0;

        if ($productId > 0) {
            $this->queue->push($productId);
        }
    }
}
