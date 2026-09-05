<?php
namespace Lp\MatterhornImport\Contract;

interface SizeResolverInterface
{
    /**
     * Return a stable supplier attribute descriptor. The generic skeleton
     * persistence layer resolves/creates the real numeric PrestaShop attribute.
     *
     * @return array{group_key:string,value_key:string,group_name:string,value:string}
     */
    public function attribute(string $size): array;
}
