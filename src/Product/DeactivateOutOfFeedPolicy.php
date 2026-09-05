<?php
namespace Lp\MatterhornImport\Product;

use Lp\MatterhornImport\Contract\OutOfFeedPolicyInterface;
use Lp\MatterhornImport\Contract\ProductWriterInterface;

final class DeactivateOutOfFeedPolicy implements OutOfFeedPolicyInterface
{
    public function __construct(private ProductWriterInterface $writer) {}
    public function apply(int $productId, int $shopId): void { $this->writer->disable($productId, $shopId); }
}
