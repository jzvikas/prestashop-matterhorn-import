<?php
namespace Lp\MatterhornImport\Util;

use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\RunRepository;

final class RunFailureRecorder
{
    public function __construct(private ErrorRepository $errors, private RunRepository $runs) {}

    public function record(int $runId, string $stage, \Throwable $error, ?string $sourceKey = null): void
    {
        try { $this->errors->add($runId, $stage, $sourceKey, $error); }
        catch (\Throwable $loggingError) { $this->fallback($runId, $stage, 'error-log', $loggingError); }
        try { $this->runs->stage($runId, $stage, 'failed'); }
        catch (\Throwable $stageError) { $this->fallback($runId, $stage, 'stage-state', $stageError); }
        try { $this->runs->finish($runId, 'failed'); }
        catch (\Throwable $finishError) { $this->fallback($runId, $stage, 'run-state', $finishError); }
    }

    private function fallback(int $runId, string $stage, string $operation, \Throwable $error): void
    {
        error_log(sprintf('[matterhornimport] failure recording degraded run=%d stage=%s operation=%s error=%s', $runId, $stage, $operation, $error->getMessage()));
    }
}
