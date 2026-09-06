<?php
namespace Lp\MatterhornImport\Database;

use Doctrine\DBAL\Connection;

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
     * dropped it (notably shared hosting with wait_timeout=30).
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
            if (!$this->isConnectionLost($exception)) {
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
                throw new \RuntimeException('Could not configure Matterhorn AJAX database session.');
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

    private function isConnectionLost(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (
                stripos($message, 'MySQL server has gone away') !== false
                || stripos($message, 'Lost connection to MySQL server') !== false
                || stripos($message, 'Doctrine\\DBAL\\Exception\\ConnectionLost') !== false
                || preg_match('/SQLSTATE\[HY000\].*(?:2006|2013)/i', $message) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
