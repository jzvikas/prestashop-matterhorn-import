<?php
namespace Lp\MatterhornImport\Contract;

use Lp\MatterhornImport\DTO\ProductData;

interface ProductWriterInterface
{
    public function create(ProductData $data, int $shopId): int;
    public function update(int $productId, ProductData $data, int $shopId): void;
    public function disable(int $productId, int $shopId): void;
}
