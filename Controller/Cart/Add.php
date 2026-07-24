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
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Waiter24\Export\Model\Config;

/**
 * Waiter24 cart-bridge: POST /waiter24/cart/add
 *
 * Implements the platform-neutral contract the chat widget speaks on every
 * CMS: JSON body {"product_id": "123", "qty": 2, "addons": [{"name","price","qty"}]}
 * → {"success": true}. product_id may be a configurable's child (the
 * variation the guest picked in chat) — the simple is added directly, exactly
 * what the export promises via each variation's external_id.
 *
 * Add-ons (see docs/addons.md in the main waiter-saas repo) are quantity-priced
 * dish extras — not real products. They ride the buy-request's `custom_price`
 * (product base price + sum of add-on price×qty, requires setIsSuperMode()) so
 * the line total is correct, and are additionally recorded as a native
 * 'additional_options' quote-item option so cart/checkout/order templates
 * display them under the line. Magento core has changed how it (de)serializes
 * 'additional_options' across versions (json vs php serialize) — verify this
 * against the installed Magento version if the display doesn't render.
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

        [$productId, $qty, $addons] = $this->parseInput();
        if ($productId === null) {
            return $result->setData(['success' => false, 'error' => 'product_id is required']);
        }

        try {
            $product = $this->productRepository->getById($productId);
            $quote   = $this->checkoutSession->getQuote();

            $buyRequest = new DataObject(['qty' => $qty]);

            $addonTotal = 0.0;
            foreach ($addons as $addon) {
                $addonTotal += (float) $addon['price'] * (int) $addon['qty'];
            }

            if ($addonTotal > 0.0) {
                // Native buy-request mechanism for a per-line custom price —
                // requires super mode, otherwise Magento silently ignores a
                // customer-supplied price (its normal fraud guard).
                $product->setIsSuperMode(true);
                $buyRequest->setData('custom_price', round((float) $product->getFinalPrice() + $addonTotal, 2));
            }

            // addProduct() reports validation problems (out of stock, required
            // options…) as a string instead of throwing; on success it returns
            // the new/updated Quote\Item.
            $added = $quote->addProduct($product, $buyRequest);
            if (is_string($added)) {
                return $result->setData(['success' => false, 'error' => $added]);
            }

            if (! empty($addons) && $added instanceof QuoteItem) {
                $added->addOption([
                    'code'  => 'additional_options',
                    'value' => $this->json->serialize(array_map(
                        static fn (array $a): array => [
                            'label' => (string) $a['name'],
                            'value' => (int) $a['qty'] > 1 ? sprintf('×%d', (int) $a['qty']) : '',
                        ],
                        $addons,
                    )),
                ]);
            }

            $quote->collectTotals();
            $this->cartRepository->save($quote);

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: ?int, 1: int, 2: list<array{name:string,price:float,qty:int}>} */
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

        $addons = [];
        foreach ((array) ($body['addons'] ?? []) as $a) {
            if (! is_array($a) || empty($a['name'])) {
                continue;
            }
            $addons[] = [
                'name'  => (string) $a['name'],
                'price' => isset($a['price']) ? (float) $a['price'] : 0.0,
                'qty'   => isset($a['qty']) ? max(1, (int) $a['qty']) : 1,
            ];
        }

        return [
            is_numeric($productId) ? (int) $productId : null,
            max(1, (int) ($qty ?: 1)),
            $addons,
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
