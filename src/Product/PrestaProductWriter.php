<?php
namespace Lp\MatterhornImport\Product;

use Lp\MatterhornImport\Category\CategorySynchronizer;
use Lp\MatterhornImport\Contract\GranularProductWriterInterface;
use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Manufacturer\ManufacturerResolver;
use Lp\MatterhornImport\Util\ShopContextManager;

final class PrestaProductWriter implements GranularProductWriterInterface
{
    public function __construct(private ShopContextManager $shopContext, private CategorySynchronizer $categories, private ManufacturerResolver $manufacturers, private ProductShopAssociationManager $associations) {}

    public function create(ProductData $data, int $shopId): int
    {
        $this->shopContext->activate($shopId);
        $p = new \Product();
        $this->applyCore($p, $data, $shopId);
        $p->price = $data->price;
        $p->id_shop_list = [$shopId];
        if (!$p->add()) { throw new \RuntimeException('PrestaShop product create failed for ' . $data->sourceKey); }
        $this->associations->ensure((int) $p->id, $shopId);
        \StockAvailable::setQuantity((int) $p->id, 0, $data->quantity, $shopId);
        $this->categories->sync((int) $p->id, $data, $shopId);
        return (int) $p->id;
    }

    public function update(int $productId, ProductData $data, int $shopId): void { $this->updateDomains($productId, $data, $shopId, ['core','price','stock','category']); }

    public function updateDomains(int $productId, ProductData $data, int $shopId, array $domains): void
    {
        if ($domains === []) { return; }
        $this->shopContext->activate($shopId);
        $this->associations->ensure($productId, $shopId);
        $domains = array_values(array_unique($domains));
        $core = in_array('core', $domains, true);
        $needsProduct = $core || in_array('price', $domains, true);
        if ($needsProduct) {
            if ($core) {
                $this->associations->assertExclusiveGlobalOwnership($productId, $shopId);
            }
            $p = new \Product($productId, false, null, $shopId);
            if (!\Validate::isLoadedObject($p)) { throw new \RuntimeException('Product not found: ' . $productId); }
            if ($core) { $this->applyCore($p, $data, $shopId); }
            if (in_array('price', $domains, true)) { $p->price = $data->price; }
            if (!$p->update()) { throw new \RuntimeException('PrestaShop product update failed: ' . $productId); }
        }
        if (in_array('stock', $domains, true)) { \StockAvailable::setQuantity($productId, 0, $data->quantity, $shopId); }
        if (in_array('category', $domains, true)) { $this->categories->sync($productId, $data, $shopId); }
        foreach (['attribute' => 'attributes', 'feature' => 'features'] as $domain => $extraKey) {
            if (in_array($domain, $domains, true) && !empty($data->extra[$extraKey])) {
                throw new \RuntimeException('Default PrestaProductWriter cannot persist ' . $domain . ' changes; use domain synchronizer');
            }
        }
    }

    public function disable(int $productId, int $shopId): void
    {
        $this->shopContext->activate($shopId);
        $this->associations->ensure($productId, $shopId);
        $p = new \Product($productId, false, null, $shopId);
        if (!\Validate::isLoadedObject($p)) { return; }
        $p->active = false;
        if (!$p->update()) { throw new \RuntimeException('Cannot disable product ' . $productId); }

        \StockAvailable::setQuantity($productId, 0, 0, $shopId);
        foreach (\Product::getProductAttributesIds($productId) as $attributeRow) {
            $attributeId = (int) ($attributeRow['id_product_attribute'] ?? 0);
            if ($attributeId > 0) {
                \StockAvailable::setQuantity($productId, $attributeId, 0, $shopId);
            }
        }
    }

    private function applyCore(\Product $p, ProductData $data, int $shopId): void
    {
        $p->reference = $data->reference;
        $p->active = $data->active;
        $manufacturer = $data->extra['manufacturer'] ?? null;
        if (is_array($manufacturer)) {
            $manufacturerName = (string) ($manufacturer['name'] ?? '');
            $autoCreate = !array_key_exists('auto_create', $manufacturer) || (bool) $manufacturer['auto_create'];
        } else {
            $manufacturerName = is_string($manufacturer) ? $manufacturer : '';
            $autoCreate = true;
        }
        if ($manufacturerName !== '' || array_key_exists('manufacturer', $data->extra)) { $p->id_manufacturer = $this->manufacturers->resolve($manufacturerName, $shopId, $autoCreate); }
        foreach (\Language::getLanguages(false, $shopId) as $lang) {
            $id = (int) $lang['id_lang'];
            $name = $data->name[$id] ?? $data->name['default'] ?? $data->reference;
            $p->name[$id] = $name;
            $p->link_rewrite[$id] = \Tools::str2url($name);
        }
    }
}
