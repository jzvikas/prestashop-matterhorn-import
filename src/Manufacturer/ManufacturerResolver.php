<?php
namespace Lp\MatterhornImport\Manufacturer;

use Lp\MatterhornImport\Util\ShopContextManager;

final class ManufacturerResolver
{
    /** @var array<string,int> */
    private array $cache = [];
    public function __construct(private ShopContextManager $shopContext) {}

    public function resolve(?string $name, int $shopId, bool $autoCreate = true): int
    {
        $name = trim((string) $name);
        if ($name === '') { return 0; }
        if ($shopId <= 0) { throw new \InvalidArgumentException('Manufacturer resolver requires a concrete shop id.'); }
        if (mb_strlen($name) > 64) { throw new \InvalidArgumentException('Manufacturer name exceeds PrestaShop limit.'); }
        $key = $shopId . ':' . mb_strtolower($name, 'UTF-8');
        if (isset($this->cache[$key])) { return $this->cache[$key]; }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $lock = 'lpimp:mfr:' . substr(hash('sha256', mb_strtolower($name, 'UTF-8')), 0, 32);
        if ((int) $db->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', 10)") !== 1) { throw new \RuntimeException('Cannot acquire manufacturer resolver lock.'); }
        try {
            $id = (int) $db->getValue('SELECT id_manufacturer FROM `' . _DB_PREFIX_ . 'manufacturer` ' . "WHERE LOWER(name)=LOWER('" . pSQL($name) . "') ORDER BY id_manufacturer ASC");
            if ($id <= 0) {
                if (!$autoCreate) { return 0; }
                $manufacturer = new \Manufacturer();
                $manufacturer->name = $name;
                $manufacturer->active = true;
                if (!$manufacturer->add()) { throw new \RuntimeException('Cannot create manufacturer: ' . $name); }
                $id = (int) $manufacturer->id;
            }
            $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'manufacturer_shop` (id_manufacturer,id_shop) VALUES (' . $id . ',' . $shopId . ')');
            if (!(bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'manufacturer_shop` WHERE id_manufacturer=' . $id . ' AND id_shop=' . $shopId)) {
                throw new \RuntimeException('Cannot associate manufacturer #' . $id . ' to shop #' . $shopId);
            }
            return $this->cache[$key] = $id;
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')");
        }
    }
}
