<?php
namespace Lp\MatterhornImport\Product;

use Lp\MatterhornImport\DTO\ProductData;
use Lp\MatterhornImport\Util\ShopContextManager;

final class InterruptedCreateRecovery
{
    public function __construct(private ShopContextManager $shopContext) {}

    public function findRecoverable(int $shopId, string $source, ProductData $data, string $runStartedAt): int
    {
        if ($shopId <= 0 || trim($source) === '' || trim($runStartedAt) === '') {
            throw new \InvalidArgumentException('Interrupted-create recovery requires shop, source and run start time');
        }
        $reference = trim($data->reference);
        if ($reference === '') { return 0; }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $rows = $db->executeS(sprintf(
            "SELECT p.id_product,p.reference,p.date_add,ps.price,ps.active FROM `%sproduct` p " .
            "INNER JOIN `%sproduct_shop` ps ON ps.id_product=p.id_product AND ps.id_shop=%d " .
            "LEFT JOIN `%sli_matterhornim_99dfbf_mapping` owned ON owned.id_shop=%d AND owned.id_product=p.id_product " .
            "WHERE p.reference='%s' AND p.date_add>='%s' AND owned.id_product IS NULL ORDER BY p.id_product ASC LIMIT 3",
            _DB_PREFIX_, _DB_PREFIX_, $shopId, _DB_PREFIX_, $shopId, pSQL($reference), pSQL($runStartedAt)
        ), true, false);
        if (!is_array($rows)) { throw new \RuntimeException('Interrupted-create recovery candidate query failed: ' . $db->getMsgError()); }
        if ($rows === []) { return 0; }
        if (count($rows) !== 1) {
            throw new \RuntimeException('Interrupted-create recovery is ambiguous for reference ' . $reference . ': ' . count($rows) . ' unmapped candidates');
        }
        $candidate = $rows[0];
        $productId = (int) ($candidate['id_product'] ?? 0);
        if ($productId <= 0) { throw new \RuntimeException('Interrupted-create recovery returned invalid product ID'); }
        if ((int) ($candidate['active'] ?? -1) !== ($data->active ? 1 : 0)) {
            throw new \RuntimeException('Interrupted-create candidate differs for reference ' . $reference . ' (active)');
        }
        if (abs((float) ($candidate['price'] ?? 0.0) - $data->price) > 0.000001) {
            throw new \RuntimeException('Interrupted-create candidate differs for reference ' . $reference . ' (price)');
        }
        $expectedNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => trim((string) $name), $data->name
        ), static fn(string $name): bool => $name !== '')));
        if ($expectedNames === []) { $expectedNames = [$reference]; }
        $quoted = implode(',', array_map(static fn(string $name): string => "'" . pSQL($name) . "'", $expectedNames));
        $nameCount = $db->getValue(sprintf(
            "SELECT COUNT(*) FROM `%sproduct_lang` WHERE id_product=%d AND id_shop=%d AND name IN (%s)",
            _DB_PREFIX_, $productId, $shopId, $quoted
        ), false);
        if ($nameCount === false) { throw new \RuntimeException('Interrupted-create recovery name verification failed: ' . $db->getMsgError()); }
        if ((int) $nameCount <= 0) { throw new \RuntimeException('Interrupted-create candidate differs for reference ' . $reference . ' (name)'); }
        return $productId;
    }
}
