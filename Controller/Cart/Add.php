<?php

namespace Waiter24\Export\Controller\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Waiter24\Export\Model\Config;

/**
 * Waiter24 cart-bridge: POST /waiter24/cart/add
 *
 * Implements the platform-neutral contract the chat widget speaks on every
 * CMS: JSON body {"product_id": "123", "qty": 2} → {"success": true}.
 * product_id may be a configurable's child (the variation the guest picked in
 * chat) — the simple is added directly, exactly what the export promises via
 * each variation's external_id.
 *
 * Adding happens server-side against the visitor's own quote (session cookie),
 * so it works identically on Luma, Hyvä or any other theme and needs no
 * form_key: this endpoint is CSRF-exempt like Magento's own guest cart APIs.
 * The widget only calls it same-origin.
 */
class Add implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $json,
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly Config $config,
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (! $this->config->isWidgetEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'error' => 'disabled']);
        }

        [$productId, $qty] = $this->parseInput();
        if ($productId === null) {
            return $result->setData(['success' => false, 'error' => 'product_id is required']);
        }

        try {
            $product = $this->productRepository->getById($productId);
            $quote   = $this->checkoutSession->getQuote();

            // addProduct() reports validation problems (out of stock, required
            // options…) as a string instead of throwing.
            $added = $quote->addProduct($product, $qty);
            if (is_string($added)) {
                return $result->setData(['success' => false, 'error' => $added]);
            }

            $quote->collectTotals();
            $this->cartRepository->save($quote);

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: ?int, 1: int} */
    private function parseInput(): array
    {
        $body = [];
        try {
            $body = (array) $this->json->unserialize((string) $this->request->getContent());
        } catch (\Throwable) {
            // Fall through to form fields.
        }

        $productId = $body['product_id'] ?? $this->request->getParam('product_id');
        $qty       = $body['qty'] ?? $this->request->getParam('qty');

        return [
            is_numeric($productId) ? (int) $productId : null,
            max(1, (int) ($qty ?: 1)),
        ];
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
