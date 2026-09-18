<?php

namespace Waiter24\Export\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Waiter24\Export\Model\RealtimeQueue;

/**
 * Queues a product for the debounced realtime push whenever it is saved —
 * price, description, status, visibility, or a brand new product going live.
 * The queue only ever holds the id; RealtimePush re-reads the product fresh
 * when it flushes, so whatever is current at that point is what gets sent.
 */
class MarkProductDirty implements ObserverInterface
{
    public function __construct(private readonly RealtimeQueue $queue) {}

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        $id = $product ? (int) $product->getId() : 0;

        if ($id > 0) {
            $this->queue->push($id);
        }
    }
}
