<?php
namespace Lp\MatterhornImport\Util;

final class TransactionState
{
    private static ?bool $mariaDb = null;

    public static function isActive(\Db $db): bool
    {
        if (self::$mariaDb === null) {
            $version = $db->getValue('SELECT VERSION()', false);
            if ($version === false || trim((string) $version) === '') {
                throw new \RuntimeException(
                    'Could not identify the database server while inspecting the transaction state: ' .
                    $db->getMsgError()
                );
            }
            self::$mariaDb = stripos((string) $version, 'MariaDB') !== false;
        }

        if (self::$mariaDb) {
            $value = $db->getValue('SELECT @@session.in_transaction', false);
            if ($value === false) {
                throw new \RuntimeException(
                    'Could not inspect the MariaDB transaction state: ' . $db->getMsgError()
                );
            }

            return (int) $value === 1;
        }

        try {
            // MySQL exposes transaction events through Performance Schema rather than
            // MariaDB's @@session.in_transaction variable. Keep the lookup scoped to
            // this exact connection and bypass PrestaShop's scalar-query cache.
            $value = $db->getValue(
                "SELECT CASE WHEN EXISTS (" .
                "SELECT 1 FROM performance_schema.events_transactions_current tx " .
                "INNER JOIN performance_schema.threads th ON th.THREAD_ID=tx.THREAD_ID " .
                "WHERE th.PROCESSLIST_ID=CONNECTION_ID() AND tx.STATE='ACTIVE'" .
                ') THEN 1 ELSE 0 END',
                false
            );
            if ($value !== false) {
                return (int) $value === 1;
            }
        } catch (\Throwable $exception) {
            // Some managed MySQL-compatible services restrict Performance Schema.
            // The savepoint probe below is connection-local and portable.
        }

        return self::probeWithSavepoint($db);
    }

    private static function probeWithSavepoint(\Db $db): bool
    {
        $savepoint = 'lp_matterhorn_tx_probe_' . bin2hex(random_bytes(6));

        try {
            if (!$db->execute('SAVEPOINT ' . $savepoint)) {
                throw new \RuntimeException($db->getMsgError(), (int) $db->getNumberError());
            }
            if (!$db->execute('RELEASE SAVEPOINT ' . $savepoint)) {
                $number = (int) $db->getNumberError();
                if ($number === 1305) {
                    return false;
                }
                throw new \RuntimeException($db->getMsgError(), $number);
            }

            return true;
        } catch (\Throwable $exception) {
            $number = (int) ($exception->getCode() ?: $db->getNumberError());
            $message = $exception->getMessage() . ' ' . $db->getMsgError();
            if (
                $number === 1305
                || (stripos($message, 'SAVEPOINT') !== false && stripos($message, 'does not exist') !== false)
            ) {
                return false;
            }

            throw new \RuntimeException(
                'Could not inspect the MySQL-compatible transaction state: ' . trim($message),
                0,
                $exception
            );
        }
    }
}
