<?php
namespace Lp\MatterhornImport\Contract;

interface SourceInterface
{
    public function name(): string;
    public function rows(): iterable;
}
