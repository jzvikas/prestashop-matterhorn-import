<?php
namespace Lp\MatterhornImport\Contract;

interface ByteCheckpointableSourceInterface extends CheckpointableSourceInterface
{
    public function rowsFromByte(int $byteOffset, int $recordOffset = 0): iterable;

    public function byteCheckpoint(): int;
}
