<?php
namespace Lp\MatterhornImport\Repository;

final class RunRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_run';

    public function create(int $shopId, string $source): int
    {
        $source = trim($source);
        if ($shopId <= 0 || $source === '') {
            throw new \InvalidArgumentException('Run requires a concrete shop and source');
        }
        if (!\Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $shopId,
            'source' => pSQL($source),
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new \RuntimeException('Could not create Matterhorn import run');
        }
        $id = (int) \Db::getInstance()->Insert_ID();
        if ($id <= 0) {
            throw new \RuntimeException('Created Matterhorn run has invalid ID');
        }
        return $id;
    }

    public function get(int $runId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run=' . (int) $runId
        );
        return is_array($row) ? $row : null;
    }

    public function assertContext(int $runId, int $shopId, string $source): array
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Matterhorn import run not found: ' . $runId);
        }
        if ((int) $run['id_shop'] !== $shopId || (string) $run['source'] !== $source) {
            throw new \RuntimeException('Run/shop/source mismatch; stage execution blocked');
        }
        return $run;
    }

    public function stage(int $runId, string $stage, string $status): void
    {
        if (!in_array($stage, ['read','import','update','remove'], true)) {
            throw new \InvalidArgumentException('Invalid import stage: ' . $stage);
        }
        if (!in_array($status, ['pending','running','completed','failed','paused'], true)) {
            throw new \InvalidArgumentException('Invalid stage status: ' . $status);
        }
        if (!\Db::getInstance()->update(self::TABLE, [$stage . '_status' => pSQL($status)], 'id_run=' . (int) $runId)) {
            throw new \RuntimeException('Could not update Matterhorn stage status');
        }
    }

    public function increment(int $runId, string $field, int $by = 1): void
    {
        $allowed = ['source_total','source_valid','source_invalid','source_duplicate','import_done','import_failed','update_done','update_skipped','update_failed','remove_done','remove_failed'];
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid run counter: ' . $field);
        }
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `' . $field . '`=`' . $field . '`+' . (int) $by . ' WHERE id_run=' . (int) $runId
        )) {
            throw new \RuntimeException('Could not increment run counter: ' . $field);
        }
    }

    public function resetStageFailureCounter(int $runId, string $stage): void
    {
        $field = match ($stage) {
            'import' => 'import_failed',
            'update' => 'update_failed',
            'remove' => 'remove_failed',
            default => throw new \InvalidArgumentException('Stage has no item failure counter: ' . $stage),
        };
        if (!\Db::getInstance()->update(self::TABLE, [$field => 0], 'id_run=' . (int) $runId)) {
            throw new \RuntimeException('Could not reset stage failure counter');
        }
    }

    public function resetRead(int $runId, ?string $fingerprint, string $policyHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $policyHash)) {
            throw new \InvalidArgumentException('READ policy hash must be a SHA-256 value');
        }
        if (!\Db::getInstance()->update(self::TABLE, [
            'status' => 'running',
            'read_status' => 'pending',
            'source_total' => 0,
            'source_valid' => 0,
            'source_invalid' => 0,
            'source_duplicate' => 0,
            'read_checkpoint' => 0,
            'source_fingerprint' => $fingerprint === null ? null : pSQL($fingerprint),
            'source_policy_hash' => pSQL($policyHash),
            'finished_at' => null,
        ], 'id_run=' . (int) $runId, 0, true)) {
            throw new \RuntimeException('Could not reset Matterhorn READ state');
        }
    }

    public function commitReadProgress(int $runId, int $checkpoint, int $total, int $valid, int $invalid): void
    {
        if ($checkpoint < 0 || $total < 0 || $valid < 0 || $invalid < 0 || $valid + $invalid !== $total) {
            throw new \InvalidArgumentException('Invalid READ progress values');
        }
        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET ' .
            '`read_checkpoint`=' . $checkpoint . ',' .
            '`source_total`=`source_total`+' . $total . ',' .
            '`source_valid`=`source_valid`+' . $valid . ',' .
            '`source_invalid`=`source_invalid`+' . $invalid .
            ' WHERE id_run=' . (int) $runId;
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Could not persist READ checkpoint');
        }
    }

    public function setReadDuplicate(int $runId, int $duplicates): void
    {
        if ($duplicates < 0) {
            throw new \InvalidArgumentException('Duplicate count cannot be negative');
        }
        if (!\Db::getInstance()->update(self::TABLE, ['source_duplicate' => $duplicates], 'id_run=' . (int) $runId)) {
            throw new \RuntimeException('Could not update duplicate counter');
        }
    }

    public function resume(int $runId): void
    {
        if (!\Db::getInstance()->update(self::TABLE, ['status' => 'running', 'finished_at' => null], 'id_run=' . (int) $runId, 0, true)) {
            throw new \RuntimeException('Could not resume Matterhorn import run');
        }
    }

    public function finish(int $runId, string $status = 'completed'): void
    {
        if (!in_array($status, ['completed','failed','paused'], true)) {
            throw new \InvalidArgumentException('Invalid run status: ' . $status);
        }
        if (!\Db::getInstance()->update(self::TABLE, [
            'status' => pSQL($status),
            'finished_at' => date('Y-m-d H:i:s'),
        ], 'id_run=' . (int) $runId)) {
            throw new \RuntimeException('Could not finish Matterhorn import run');
        }
    }

    public function previousCompleted(int $runId, int $shopId, string $source): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run<' . (int) $runId .
            ' AND id_shop=' . (int) $shopId . " AND source='" . pSQL($source) .
            "' AND status='completed' ORDER BY id_run DESC"
        );
        return is_array($row) ? $row : null;
    }

    public function latest(int $shopId, string $source): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_shop=' . (int) $shopId .
            " AND source='" . pSQL($source) . "' ORDER BY id_run DESC"
        );
        return is_array($row) ? $row : null;
    }
}
