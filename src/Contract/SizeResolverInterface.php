<?php
namespace Lp\MatterhornImport\Contract;

interface SizeResolverInterface
{
    public function resolve(string $size): int;
}
