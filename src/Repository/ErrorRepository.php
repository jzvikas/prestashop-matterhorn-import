<?php
namespace Lp\MatterhornImport\Repository;

final class ErrorRepository
{
    private const TABLE = 'li_matterhornim_99dfbf_error';
    private const WARNING_PREFIX = 'WARNING: ';

    public function add(int $runId, string $stage, ?string $sourceKey, \Throwable|string $error): void
    {
        $message = $error instanceof \Throwable ? get_class($error) . ': ' . $error->getMessage() : (string) $error;
        $ok = \Db::getInstance()->insert(self::TABLE, [
            'id_run' => $runId,
            'stage' => pSQL($stage),
            'source_key' => $sourceKey === null || $sourceKey === '' ? null : pSQL(mb_substr($sourceKey, 0, 191)),
            'message' => pSQL(mb_substr($message, 0, 8000), true),
            'created_at' => date('Y-m-d H:i:s'),
        ], true);
        if ($error instanceof \Throwable) {
            error_log(sprintf(
                '[matterhornimport] item failure run=%d stage=%s source_key=%s message=%s',
                $runId,
                $stage,
                $sourceKey ?? '-',
                mb_substr($message, 0, 1000)
            ));
        }
        if (!$ok) {
            $persistenceError = sprintf(
                'Matterhorn error observability persistence failed for run=%d stage=%s source_key=%s',
                $runId,
                $stage,
                $sourceKey ?? '-'
            );
            error_log(sprintf(
                '[matterhornimport] %s message=%s db_error=%s',
                $persistenceError,
                mb_substr($message, 0, 1000),
                mb_substr((string) \Db::getInstance()->getMsgError(), 0, 1000)
            ));
            throw new \RuntimeException($persistenceError);
        }
    }

    public function purgeStage(int $runId, string $stage): int
    {
        if (!in_array($stage, ['read','import','update','remove','image'], true)) {
            throw new \InvalidArgumentException('Invalid error stage');
        }
        return (int) \Db::getInstance()->delete(self::TABLE, 'id_run=' . (int) $runId . " AND stage='" . pSQL($stage) . "'");
    }

    public function countForRun(int $runId): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_run=' . (int) $runId,
            false
        );
    }

    public function countWarningsForRun(int $runId): int
    {
        return (int) \Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . self::TABLE . "` WHERE id_run=" . (int) $runId .
            " AND message LIKE '" . pSQL(self::WARNING_PREFIX) . "%'",
            false
        );
    }

    public function countErrorsForRun(int $runId): int
    {
        return (int) \Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . self::TABLE . "` WHERE id_run=" . (int) $runId .
            " AND message NOT LIKE '" . pSQL(self::WARNING_PREFIX) . "%'",
            false
        );
    }
}
