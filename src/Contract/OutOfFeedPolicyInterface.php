<?php
namespace Lp\MatterhornImport\Contract;

interface OutOfFeedPolicyInterface
{
    public function apply(int $productId, int $shopId): void;
}
