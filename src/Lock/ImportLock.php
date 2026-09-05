<?php
namespace Lp\MatterhornImport\Lock;

final class ImportLock
{
    private ?string $name = null;

    public function acquire(int $shopId, string $source, int $timeout = 0): bool
    {
        if ($this->name !== null) { throw new \LogicException('ImportLock instance already owns a lock'); }
        $identity = $shopId . ':' . $source;
        $this->name = 'matterhornimport:' . $shopId . ':' . substr(hash('sha256', $identity), 0, 32);
        $result = \Db::getInstance()->getValue(
            sprintf("SELECT GET_LOCK('%s',%d)", pSQL($this->name), max(0, $timeout)),
            false
        );
        if ((string) $result !== '1') { $this->name = null; return false; }
        return true;
    }

    public function release(): void
    {
        if ($this->name === null) { return; }
        $name = $this->name;
        $this->name = null;
        try { \Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')", false); } catch (\Throwable) {}
    }

    public function __destruct() { $this->release(); }
}
