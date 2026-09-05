<?php
namespace Lp\MatterhornImport\Util;

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
    private int $recoveryCount = 0;

    public function arm(\Db $db, ?string $savepoint = null): void
    {
        if ($savepoint !== null && !preg_match('/^[A-Za-z0-9_]+$/D', $savepoint)) {
            throw new \InvalidArgumentException('Invalid item transaction savepoint name');
        }
        $this->db = $db;
        $this->savepoint = $savepoint;
        $this->recoveryCount = 0;
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
        $this->recoveryCount = 0;
    }
}
