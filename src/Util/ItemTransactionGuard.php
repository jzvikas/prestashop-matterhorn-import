<?php
namespace Lp\MatterhornImport\Util;

use Lp\MatterhornImport\Repository\RunRepository;

/**
 * Restores a caller-owned item transaction after PrestaShop ObjectModel/hooks commit
 * the shared DB connection. Import/update/remove stages arm the shared guard for the
 * current item; nested domain services only ask it to restore before module-owned
 * durability writes. Calls are harmless when no stage-owned item transaction is armed.
 */
final class ItemTransactionGuard
{
    private ?\Db $db = null;
    private ?string $savepoint = null;
    private ?int $runId = null;
    private int $recoveryCount = 0;

    public function __construct(private ?RunRepository $runs = null)
    {
    }

    public function arm(\Db $db, ?string $savepoint = null, ?int $runId = null): void
    {
        if ($savepoint !== null && !preg_match('/^[A-Za-z0-9_]+$/D', $savepoint)) {
            throw new \InvalidArgumentException('Invalid item transaction savepoint name');
        }
        if ($runId !== null && $runId <= 0) {
            throw new \InvalidArgumentException('Item transaction run ID must be positive');
        }
        $this->db = $db;
        $this->savepoint = $savepoint;
        $this->runId = $runId;
        $this->recoveryCount = 0;
        if ($runId !== null) {
            $this->requireRunRepository()->lockRunning($runId);
        }
    }

    /** @return bool true when an externally committed transaction had to be recreated */
    public function restoreAfterExternalCommit(): bool
    {
        if ($this->db === null) { return false; }

        $value = $this->db->getValue('SELECT @@session.in_transaction', false);
        if ($value === false) {
            throw new \RuntimeException('Could not inspect item transaction state: ' . $this->db->getMsgError());
        }
        if ((int) $value === 1) { return false; }

        if (!$this->db->execute('START TRANSACTION')) {
            throw new \RuntimeException('Could not restore item transaction after PrestaShop external commit');
        }
        if ($this->savepoint !== null && !$this->db->execute('SAVEPOINT ' . $this->savepoint)) {
            $this->db->execute('ROLLBACK');
            throw new \RuntimeException(
                'Could not restore item savepoint after PrestaShop external commit: ' . $this->db->getMsgError()
            );
        }
        try {
            if ($this->runId !== null) {
                $this->requireRunRepository()->lockRunning($this->runId);
            }
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
        $this->recoveryCount++;
        return true;
    }

    public function recoveryCount(): int
    {
        return $this->recoveryCount;
    }

    public function disarm(): void
    {
        $this->db = null;
        $this->savepoint = null;
        $this->runId = null;
        $this->recoveryCount = 0;
    }

    private function requireRunRepository(): RunRepository
    {
        if ($this->runs === null) {
            throw new \LogicException('RunRepository is required when arming a run cancellation fence');
        }
        return $this->runs;
    }
}
