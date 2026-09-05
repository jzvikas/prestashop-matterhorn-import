<?php
namespace Lp\MatterhornImport\Contract;

interface CheckpointableSourceInterface extends SourceInterface
{
    public function fingerprint(): string;
    public function rowsFrom(int $offset): iterable;
}
