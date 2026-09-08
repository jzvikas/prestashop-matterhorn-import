<?php
namespace Lp\MatterhornImport\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;

final class AjaxDatabaseSessionGuard
{
    public const WAIT_TIMEOUT_SECONDS = 300;
    public const NET_READ_TIMEOUT_SECONDS = 120;
    public const NET_WRITE_TIMEOUT_SECONDS = 120;

    public function __construct(private Connection $doctrineConnection)
    {
    }

    /**
     * Prepare Doctrine before the Back Office security layer starts querying again.
     * A stale persistent connection is re-opened once when MySQL has already
     * dropped it (notably shared hosting with a low wait_timeout).
     */
    public function prepareDoctrine(): void
    {
        try {
            $this->applyDoctrineSession();
        } catch (\Throwable $exception) {
            if (!$this->isConnectionLost($exception)) {
                throw $exception;
            }

            $this->doctrineConnection->close();
            $this->doctrineConnection->connect();
            $this->applyDoctrineSession();
        }
    }

    /**
     * PrestaShop legacy repositories use Db::getInstance(), which is a separate
     * connection from Doctrine on many installations. Give that connection the
     * same per-request timeout envelope before a bounded import batch starts.
     */
    public function prepareLegacy(): void
    {
        $db = \Db::getInstance();

        try {
            $this->applyLegacySession($db);
        } catch (\Throwable $exception) {
            if (!$this->isConnectionLost($exception) && !$this->legacyConnectionLost($db)) {
                throw $exception;
            }

            $db->disconnect();
            $db->connect();
            $this->applyLegacySession($db);
        }
    }

    private function applyDoctrineSession(): void
    {
        foreach ($this->sessionStatements() as $sql) {
            $this->doctrineConnection->executeStatement($sql);
        }
    }

    private function applyLegacySession(\Db $db): void
    {
        foreach ($this->sessionStatements() as $sql) {
            if (!$db->execute($sql)) {
                throw new \RuntimeException(
                    'Could not configure Matterhorn database session: ' . $db->getMsgError(),
                    (int) $db->getNumberError()
                );
            }
        }
    }

    /** @return list<string> */
    private function sessionStatements(): array
    {
        return [
            'SET SESSION wait_timeout = ' . self::WAIT_TIMEOUT_SECONDS,
            'SET SESSION net_read_timeout = ' . self::NET_READ_TIMEOUT_SECONDS,
            'SET SESSION net_write_timeout = ' . self::NET_WRITE_TIMEOUT_SECONDS,
        ];
    }

    private function legacyConnectionLost(\Db $db): bool
    {
        $number = (int) $db->getNumberError();
        if ($number === 2006 || $number === 2013) {
            return true;
        }

        $message = (string) $db->getMsgError();

        return stripos($message, 'MySQL server has gone away') !== false
            || stripos($message, 'Lost connection to MySQL server') !== false;
    }

    private function isConnectionLost(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionLost) {
                return true;
            }

            $code = (int) $current->getCode();
            if ($code === 2006 || $code === 2013) {
                return true;
            }

            $message = $current->getMessage();
            if (
                stripos($message, 'MySQL server has gone away') !== false
                || stripos($message, 'Lost connection to MySQL server') !== false
                || preg_match('/SQLSTATE\[HY000\].*(?:2006|2013)/i', $message) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
