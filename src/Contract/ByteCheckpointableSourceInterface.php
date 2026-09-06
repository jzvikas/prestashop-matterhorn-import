<?php
namespace Lp\MatterhornImport\Contract;

interface ByteCheckpointableSourceInterface extends CheckpointableSourceInterface
{
    public function byteCheckpoint(): int;
}
