<?php
namespace Lp\MatterhornImport\Contract;

use Lp\MatterhornImport\DTO\ProductData;

interface ProductMapperInterface
{
    public function map(array $row): ProductData;
}
