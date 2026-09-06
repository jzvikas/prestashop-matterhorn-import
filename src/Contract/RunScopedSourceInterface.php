<?php
namespace Lp\MatterhornImport\Contract;

interface RunScopedSourceInterface
{
    public function activateRun(int $runId, bool $resume): void;

    public function persistRunCheckpoint(int $runId, int $recordCheckpoint, int $byteCheckpoint): void;

    public function releaseRun(int $runId): void;
}
