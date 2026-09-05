<?php
namespace Lp\MatterhornImport\Product;

use Lp\MatterhornImport\Contract\GranularProductWriterInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Manufacturer\ManufacturerResolver;
use Lp\MatterhornImport\Util\ItemTransactionGuard;
use Lp\MatterhornImport\Util\ShopContextManager;

final class MatterhornProductWriter implements GranularProductWriterInterface
{
    public function __construct(
        private PrestaProductWriter $base,
        private ShopContextManager $shopContext,
        private ManufacturerResolver $manufacturers,
        private ProductShopAssociationManager $associations,
        private ItemTransactionGuard $transactionGuard
    ) {}

    public function create(ProductData $data, int $shopId): int
    {
        $productId = $this->base->create($data, $shopId);
        $this->transactionGuard->restoreAfterExternalCommit();
        $this->applySupplierCore($productId, $data, $shopId, true, false);
        $this->transactionGuard->restoreAfterExternalCommit();
        return $productId;
    }

    public function update(int $productId, ProductData $data, int $shopId): void
    {
        $this->updateDomains($productId, $data, $shopId, ['core','price','stock','category']);
    }

    public function updateDomains(int $productId, ProductData $data, int $shopId, array $domains): void
    {
        $domains = array_values(array_unique($domains));
        if ($domains === []) { return; }

        $core = in_array('core', $domains, true);
        $price = in_array('price', $domains, true);
        if ($core || $price) {
            $this->applySupplierCore($productId, $data, $shopId, $core, $price);
            $this->transactionGuard->restoreAfterExternalCommit();
        }

        $delegated = array_values(array_intersect($domains, ['stock','category']));
        if ($delegated !== []) {
            $this->base->updateDomains($productId, $data, $shopId, $delegated);
            $this->transactionGuard->restoreAfterExternalCommit();
        }

        foreach (['attribute' => 'attributes', 'feature' => 'features'] as $domain => $extraKey) {
            if (in_array($domain, $domains, true) && !empty($data->extra[$extraKey])) {
                throw new \RuntimeException('MatterhornProductWriter delegates ' . $domain . ' to the dedicated domain synchronizer');
            }
        }
    }

    public function disable(int $productId, int $shopId): void
    {
        $this->base->disable($productId, $shopId);
        $this->transactionGuard->restoreAfterExternalCommit();
    }

    private function applySupplierCore(int $productId, ProductData $data, int $shopId, bool $core, bool $price): void
    {
        $this->shopContext->activate($shopId);
        $this->associations->ensure($productId, $shopId);
        if ($core) { $this->associations->assertExclusiveGlobalOwnership($productId, $shopId); }
        $product = new \Product($productId, false, null, $shopId);
        if (!\Validate::isLoadedObject($product)) { throw new \RuntimeException('Matterhorn product not found: ' . $productId); }

        if ($core) {
            $product->reference = $data->reference;
            $product->active = $data->active;
            $manufacturer = $data->extra['manufacturer'] ?? null;
            if (is_array($manufacturer)) {
                $manufacturerName = (string) ($manufacturer['name'] ?? '');
                $autoCreate = !array_key_exists('auto_create', $manufacturer) || (bool) $manufacturer['auto_create'];
            } else {
                $manufacturerName = is_string($manufacturer) ? $manufacturer : '';
                $autoCreate = true;
            }
            if ($manufacturerName !== '' || array_key_exists('manufacturer', $data->extra)) {
                $product->id_manufacturer = $this->manufacturers->resolve($manufacturerName, $shopId, $autoCreate);
                $this->transactionGuard->restoreAfterExternalCommit();
            }

            $sourceLangId = $this->sourceLanguageId($shopId, $data);
            $name = (string) ($data->name[$sourceLangId] ?? $data->name['default'] ?? $data->reference);
            $product->name[$sourceLangId] = $name;
            $product->link_rewrite[$sourceLangId] = \Tools::str2url($name);
            if (array_key_exists('description', $data->extra)) {
                $product->description[$sourceLangId] = (string) $data->extra['description'];
            }
        }
        if ($price) { $product->price = $data->price; }
        if (!$product->update()) { throw new \RuntimeException('Matterhorn product update failed: ' . $productId); }
        $this->transactionGuard->restoreAfterExternalCommit();
        if ($price && !$core) { $this->associations->restoreDefaultShopShadows($productId, $shopId, ['price']); }
    }

    private function sourceLanguageId(int $shopId, ProductData $data): int
    {
        $shop = \Context::getContext()->shop ?? null;
        $groupId = $shop instanceof \Shop ? (int) $shop->id_shop_group : 0;
        $snapshotLanguageId = (int) ($data->extra['source_language_id'] ?? 0);
        if ($snapshotLanguageId > 0 && $this->languageBelongsToShop($snapshotLanguageId, $shopId)) { return $snapshotLanguageId; }
        $configured = (int) \Configuration::get('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', null, $groupId > 0 ? $groupId : null, $shopId);
        if ($configured > 0 && $this->languageBelongsToShop($configured, $shopId)) { return $configured; }
        $fallback = (int) \Configuration::get('PS_LANG_DEFAULT', null, $groupId > 0 ? $groupId : null, $shopId);
        if ($fallback <= 0) { $fallback = (int) \Configuration::get('PS_LANG_DEFAULT'); }
        if ($fallback <= 0 || !$this->languageBelongsToShop($fallback, $shopId)) {
            throw new \RuntimeException('Cannot resolve Matterhorn source language for shop #' . $shopId);
        }
        return $fallback;
    }

    private function languageBelongsToShop(int $languageId, int $shopId): bool
    {
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            if ((int) ($language['id_lang'] ?? 0) === $languageId) { return true; }
        }
        return false;
    }
}