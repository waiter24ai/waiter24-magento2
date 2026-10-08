<?php

namespace Waiter24\Export\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
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
    /** Edge length of the exported product photo, in pixels. */
    private const PHOTO_SIZE = 150;

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ImageHelper $imageHelper,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ConfigurableResource $configurableResource,
    ) {}

    /**
     * Products per push. A catalogue is sent in slices so that neither this
     * process nor the import endpoint ever has to hold all of it at once.
     */
    private const BATCH_SIZE = 200;

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

        // One import session spans every slice: each slice is an upsert, and only
        // the closing call tells Waiter24 which products the store no longer
        // sells. An export that dies half way therefore hides nothing — it just
        // leaves the previous menu in place.
        $session  = 'mg2' . bin2hex(random_bytes(12));
        $sent     = 0;
        $page     = 1;
        $response = ['ok' => true];

        do {
            $slice = $this->buildSlice($storeId, $page, self::BATCH_SIZE);

            $payload = [
                'items'          => $slice['items'],
                'import_session' => $session,
                'chunk'          => $page,
            ];

            // The store's selectors and endpoints do not change between slices,
            // so they ride the first one.
            if ($page === 1) {
                $payload['site_config'] = $this->siteConfig();
            }

            $response = $this->push($endpoint, $token, $payload);

            $sent += count($slice['items']);
            ++$page;
        } while ($slice['has_more']);

        if ($sent === 0) {
            throw new LocalizedException(__('Waiter24: no visible products to export.'));
        }

        return $this->push($endpoint, $token, [
            'items'          => [],
            'import_session' => $session,
            'final'          => true,
        ]);
    }

    /**
     * Push a small, explicit set of products — the realtime observers'
     * debounced queue, not a catalogue slice. A product no longer reachable in
     * this store (disabled, "Not Visible Individually", unassigned from the
     * store's website) is pushed as unavailable rather than skipped: `upsert`
     * never hides an absent item, only a full session close does (see run()).
     * A product this store never carried is skipped outright — pushing it
     * anyway would create a phantom hidden dish in a tenant's menu that never
     * had it.
     *
     * @param int[] $productIds
     * @return array<string,mixed>
     */
    public function pushProducts(array $productIds, ?int $storeId = null): array
    {
        $token    = $this->config->getImportToken($storeId);
        $endpoint = $this->config->getEndpointUrl($storeId);

        if ($token === '' || $endpoint === '') {
            throw new LocalizedException(__('Waiter24: import token or endpoint URL is not configured.'));
        }

        $simpleStock = $this->config->isSimpleStockMode($storeId);
        $currency    = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        $mediaUrl    = $this->storeManager->getStore($storeId)
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product';

        // A configurable's child ("Not Visible Individually") is never a menu
        // entry of its own — it rides its parent's variations[]. Its save or
        // stock flip must therefore re-send the parent, not push the child as a
        // phantom unavailable dish into the tenant's menu.
        $parentsOf = [];
        foreach ($productIds as $productId) {
            $parentsOf[$productId] = array_map('intval', (array) $this->configurableResource->getParentIdsByChild($productId));
        }
        $productIds = array_values(array_unique(array_merge($productIds, ...array_values($parentsOf))));

        $items = [];

        foreach ($productIds as $productId) {
            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (\Throwable) {
                // Hard-deleted, or never existed by the time the queue flushed.
                // Nothing left here to build a payload from — the scheduled
                // export is what reconciles an actual deletion.
                continue;
            }

            if (! in_array($storeId, array_map('intval', (array) $product->getStoreIds()), true)) {
                continue;
            }

            if ($this->isPubliclyVisible($product)) {
                $items[] = $this->mapProduct($product, null, $currency, $mediaUrl, $simpleStock);
            } elseif (($parentsOf[$productId] ?? []) === []) {
                $items[] = [
                    'external_id'  => (string) $product->getId(),
                    'name'         => (string) $product->getName(),
                    'is_available' => false,
                ];
            }
        }

        if ($items === []) {
            return ['ok' => true, 'items' => 0];
        }

        // Explicit upsert: the import endpoint defaults to `replace` when
        // `mode` is omitted, which would hide the tenant's entire menu but
        // for these items.
        return $this->push($endpoint, $token, [
            'items' => $items,
            'mode'  => 'upsert',
        ]);
    }

    private function isPubliclyVisible(Product $product): bool
    {
        return (int) $product->getStatus() === Product::STATUS_ENABLED
            && (int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE;
    }

    /**
     * POST one payload to the import endpoint.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws LocalizedException
     */
    private function push(string $endpoint, string $token, array $payload): array
    {
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
     * The whole catalogue in one array. Kept for callers that want it (and for
     * small stores); `run()` goes slice by slice instead.
     *
     * @return array{site_config: array<string,mixed>, items: array<int,array<string,mixed>>}
     */
    public function build(?int $storeId = null): array
    {
        $items = [];
        $page  = 1;

        do {
            $slice = $this->buildSlice($storeId, $page, self::BATCH_SIZE);
            $items = array_merge($items, $slice['items']);
            ++$page;
        } while ($slice['has_more']);

        return [
            'site_config' => $this->siteConfig(),
            'items'       => $items,
        ];
    }

    /**
     * One page of the catalogue.
     *
     * Only products a shopper can actually reach are exported: enabled, and not
     * "Not Visible Individually" — that last one is what configurable variants
     * are set to, and the assistant must not offer a product whose page does not
     * exist. `addStoreFilter()` keeps the export to the store view being pushed.
     *
     * @return array{items: array<int,array<string,mixed>>, has_more: bool}
     */
    private function buildSlice(?int $storeId, int $page, int $pageSize): array
    {
        $simpleStock = $this->config->isSimpleStockMode($storeId);
        $currency    = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        $mediaUrl    = $this->storeManager->getStore($storeId)
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'catalog/product';

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'short_description', 'price', 'special_price', 'image', 'status'])
            ->addAttributeToFilter('status', Product::STATUS_ENABLED)
            ->setVisibility([Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH])
            ->addStoreFilter($storeId)
            // Ordered by a unique key: paging over a non-unique one can shuffle
            // ties between queries and skip a product, and a product no slice
            // ever sent would be hidden when the session closes.
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($pageSize)
            ->setCurPage($page);

        $items = [];
        $sort  = ($page - 1) * $pageSize;

        foreach ($collection as $product) {
            $items[] = $this->mapProduct($product, ++$sort, $currency, $mediaUrl, $simpleStock);
        }

        return [
            'items'    => $items,
            'has_more' => $page < $collection->getLastPageNumber(),
        ];
    }

    /**
     * The module ships the waiter24 cart-bridge endpoints (see Controller/Cart),
     * so the widget can add to and read the Magento cart on any theme.
     * Panel-owned keys (cart_integration_enabled, show_go_to_cart,
     * cart_context_enabled) are deliberately absent.
     *
     * @return array<string,mixed>
     */
    private function siteConfig(): array
    {
        return [
            'platform_preset' => 'magento2',
            'ajax_add_url'    => '/waiter24/cart/add',
            'cart_read_url'   => '/waiter24/cart',
            'cart_url'        => '/checkout/cart/',
        ];
    }

    /**
     * @param ?int $sortOrder Row position within the full-catalogue export.
     *                        Omitted (null) for a realtime single-product push,
     *                        which must not overwrite the position the last
     *                        full export assigned — the import endpoint leaves
     *                        a key it never receives untouched.
     * @return array<string,mixed>
     */
    private function mapProduct(Product $product, ?int $sortOrder, string $currency, string $mediaUrl, bool $simpleStock): array
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

        // Prefer a resized 150px thumbnail over the full-size original — the
        // widget renders dish photos small, and 150px is what every Waiter24
        // integration ships (matching the WooCommerce plugin's 'thumbnail').
        // Magento generates the cached copy on demand, so no store has to have
        // made one first. Guarded on every side so the export never breaks or
        // emits a bad URL: an exception, a placeholder (no /cache/ segment), or
        // a wrong-host URL (the helper uses the current store, which in an
        // admin/CLI export may differ from $storeId) all fall back to the
        // original media URL.
        if ($photoUrl !== null) {
            try {
                $thumb = $this->imageHelper
                    ->init($product, 'product_thumbnail_image')
                    ->setImageFile($image)
                    ->resize(self::PHOTO_SIZE, self::PHOTO_SIZE)
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
        ];

        if ($sortOrder !== null) {
            $item['sort_order'] = $sortOrder;
        }

        $variations = $this->resolveVariations($product);
        if ($variations !== []) {
            $item['variations'] = $variations;
        }

        return $item;
    }

    /**
     * Map the product's category tree: shallowest category → category, one of
     * its own children → subcategory.
     *
     * Levels 0–1 are the tree root and a store's root category ("Default
     * Category") — never a menu section, even when a product is assigned to
     * one. And the subcategory must actually sit under the category: a product
     * in "Pizza" and in "Drinks › Cola" is not a "Pizza › Cola".
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCategories(Product $product): array
    {
        try {
            $categories = $product->getCategoryCollection()
                ->addAttributeToSelect(['name', 'level'])
                ->addAttributeToFilter('level', ['gteq' => 2])
                ->setOrder('level', 'ASC');
        } catch (\Throwable) {
            return [null, null];
        }

        $category    = null;
        $categoryId  = null;
        $subcategory = null;

        foreach ($categories as $cat) {
            $name = (string) $cat->getName();
            if ($name === '') {
                continue;
            }
            if ($category === null) {
                $category   = $name;
                $categoryId = (int) $cat->getId();
            } elseif ((int) $cat->getParentId() === $categoryId) {
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
