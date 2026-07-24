<?php

namespace Waiter24\Export\Model;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the platform-neutral menu JSON from the Magento catalog and pushes it
 * to the Waiter24 import endpoint. Same schema as the WooCommerce plugin and
 * Shopify app (see examples/menu-import-sample.json).
 *
 * Magento has hierarchical categories, so parent → category, child → subcategory.
 */
class MenuExporter
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ImageHelper $imageHelper,
    ) {}

    /**
     * Build + push. Returns the decoded server response on success.
     *
     * @throws LocalizedException when not configured or the push fails.
     */
    public function run(?int $storeId = null): array
    {
        $token    = $this->config->getImportToken($storeId);
        $endpoint = $this->config->getEndpointUrl($storeId);

        if ($token === '' || $endpoint === '') {
            throw new LocalizedException(__('Waiter24: import token or endpoint URL is not configured.'));
        }

        $payload = $this->build($storeId);

        $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->post($endpoint, $this->json->serialize($payload));

        $status = $this->curl->getStatus();
        $body   = $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Waiter24 export failed', ['status' => $status, 'body' => $body]);
            throw new LocalizedException(__('Waiter24: import endpoint returned HTTP %1.', $status));
        }

        try {
            return (array) $this->json->unserialize($body);
        } catch (\Throwable) {
            return ['ok' => true];
        }
    }

    /**
     * @return array{site_config: array<string,mixed>, items: array<int,array<string,mixed>>}
     */
    public function build(?int $storeId = null): array
    {
        $simpleStock = $this->config->isSimpleStockMode($storeId);
        $currency    = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        $mediaUrl    = $this->storeManager->getStore($storeId)
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product';

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'short_description', 'price', 'special_price', 'image', 'status'])
            ->addAttributeToFilter('status', Product::STATUS_ENABLED)
            ->setVisibility([Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH])
            ->addStoreFilter($storeId);

        $items = [];
        $sort  = 0;

        foreach ($collection as $product) {
            $items[] = $this->mapProduct($product, ++$sort, $currency, $mediaUrl, $simpleStock);
        }

        return [
            // The module ships the waiter24 cart-bridge endpoints (see
            // Controller/Cart), so the widget can add to and read the Magento
            // cart on any theme. Panel-owned keys (cart_integration_enabled,
            // show_go_to_cart, cart_context_enabled) are deliberately absent.
            'site_config' => [
                'platform_preset' => 'magento2',
                'ajax_add_url'    => '/waiter24/cart/add',
                'cart_read_url'   => '/waiter24/cart',
                'cart_url'        => '/checkout/cart/',
            ],
            'items'       => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapProduct(Product $product, int $sortOrder, string $currency, string $mediaUrl, bool $simpleStock): array
    {
        [$category, $subcategory] = $this->resolveCategories($product);

        $price      = (float) $product->getPrice();
        $special    = $product->getSpecialPrice();
        $salePrice  = ($special !== null && $special !== '' && (float) $special < $price)
            ? (float) $special
            : null;

        $description = $product->getData('description') ?: $product->getData('short_description');
        $description = $description ? trim(html_entity_decode(strip_tags((string) $description))) : null;

        $image     = $product->getImage();
        $photoUrl  = ($image && $image !== 'no_selection') ? $mediaUrl . $image : null;

        // Prefer a resized ~400px thumbnail over the full-size original — the
        // widget renders dish photos small. Guarded on every side so the export
        // never breaks or emits a bad URL: an exception, a placeholder (no
        // /cache/ segment), or a wrong-host URL (the helper uses the current
        // store, which in an admin/CLI export may differ from $storeId) all fall
        // back to the original media URL.
        if ($photoUrl !== null) {
            try {
                $thumb = $this->imageHelper
                    ->init($product, 'product_thumbnail_image')
                    ->setImageFile($image)
                    ->resize(400)
                    ->getUrl();
                if (is_string($thumb) && $thumb !== ''
                    && str_contains($thumb, '/cache/')
                    && parse_url($thumb, PHP_URL_HOST) === parse_url($mediaUrl, PHP_URL_HOST)) {
                    $photoUrl = $thumb;
                }
            } catch (\Throwable $e) {
                // Keep the original $photoUrl.
            }
        }

        $available = $simpleStock ? true : $this->isInStock($product);

        $item = [
            'external_id' => (string) $product->getId(),
            'category'    => $category,
            'subcategory' => $subcategory,
            'name'        => (string) $product->getName(),
            'description' => $description ?: null,
            'price'       => $price,
            'sale_price'  => $salePrice,
            'currency'    => $currency,
            'photo_url'   => $photoUrl,
            'product_url' => $product->getProductUrl(),
            'is_available' => $available,
            'sort_order'  => $sortOrder,
        ];

        $variations = $this->resolveVariations($product);
        if ($variations !== []) {
            $item['variations'] = $variations;
        }

        return $item;
    }

    /**
     * Map the product's category tree: shallowest category → category,
     * the next level down → subcategory.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCategories(Product $product): array
    {
        try {
            $categories = $product->getCategoryCollection()
                ->addAttributeToSelect(['name', 'level'])
                ->setOrder('level', 'ASC');
        } catch (\Throwable) {
            return [null, null];
        }

        $category = null;
        $subcategory = null;

        foreach ($categories as $cat) {
            $name = (string) $cat->getName();
            if ($name === '') {
                continue;
            }
            if ($category === null) {
                $category = $name;
            } elseif ($subcategory === null) {
                $subcategory = $name;
                break;
            }
        }

        return [$category, $subcategory];
    }

    /**
     * Configurable products expose their children as selectable variations.
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolveVariations(Product $product): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return [];
        }

        try {
            $children = $product->getTypeInstance()->getUsedProducts($product);
        } catch (\Throwable) {
            return [];
        }

        $variations = [];
        foreach ($children as $child) {
            if ((int) $child->getStatus() !== Product::STATUS_ENABLED) {
                continue;
            }
            $variations[] = [
                'name'  => (string) $child->getName(),
                'price' => (float) $child->getPrice(),
                // Lets the widget push the exact child the guest picked through
                // the cart bridge (POST /waiter24/cart/add).
                'external_id' => (string) $child->getId(),
            ];
        }

        return $variations;
    }

    private function isInStock(Product $product): bool
    {
        try {
            return $this->stockRegistry->getStockItem($product->getId())->getIsInStock();
        } catch (\Throwable) {
            return true;
        }
    }
}
