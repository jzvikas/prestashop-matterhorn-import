<?php
namespace Lp\MatterhornImport\Contract;

use Lp\MatterhornImport\DTO\ProductData;

interface GranularProductWriterInterface extends ProductWriterInterface
{
    /** @param list<'core'|'price'|'stock'|'attribute'|'feature'|'category'> $domains */
    public function updateDomains(int $productId, ProductData $data, int $shopId, array $domains): void;
}
