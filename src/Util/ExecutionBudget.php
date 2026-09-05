<?php
namespace Lp\MatterhornImport\Util;

final class ExecutionBudget
{
    private int $maxItems = 0;
    private int $processed = 0;
    private float $deadline = 0.0;
    private bool $signalStop = false;
    private bool $signalsInstalled = false;
    private bool $asyncSignals = false;

    public function start(int $maxItems = 0, int $timeLimitSeconds = 0): void
    {
        if ($maxItems < 0 || $timeLimitSeconds < 0) {
            throw new \InvalidArgumentException('Execution limits cannot be negative');
        }
        $this->maxItems = $maxItems;
        $this->processed = 0;
        $this->deadline = $timeLimitSeconds > 0 ? microtime(true) + $timeLimitSeconds : 0.0;
        $this->signalStop = false;
        $this->installSignals();
    }

    public function markItem(): void { $this->processed++; }

    public function shouldStop(): bool
    {
        if ($this->signalsInstalled && !$this->asyncSignals && function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        if ($this->signalStop) { return true; }
        if ($this->maxItems > 0 && $this->processed >= $this->maxItems) { return true; }
        return $this->deadline > 0.0 && microtime(true) >= $this->deadline;
    }

    public function reason(): ?string
    {
        $this->shouldStop();
        if ($this->signalStop) { return 'signal'; }
        if ($this->maxItems > 0 && $this->processed >= $this->maxItems) { return 'max_items'; }
        if ($this->deadline > 0.0 && microtime(true) >= $this->deadline) { return 'time_limit'; }
        return null;
    }

    public function processed(): int { return $this->processed; }

    private function installSignals(): void
    {
        if ($this->signalsInstalled || !function_exists('pcntl_signal')) { return; }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $this->asyncSignals = true;
        }
        foreach (['SIGTERM','SIGINT'] as $constant) {
            if (!defined($constant)) { continue; }
            pcntl_signal(constant($constant), function (): void { $this->signalStop = true; });
        }
        $this->signalsInstalled = true;
    }
}
