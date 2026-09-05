<?php
namespace Lp\MatterhornImport\Config;

final class OperationalSettings
{
    public const BATCH_SIZE = 'MATTERHORNIMPORT_BATCH_SIZE';
    public const MAX_ITEMS = 'MATTERHORNIMPORT_MAX_ITEMS';
    public const TIME_LIMIT = 'MATTERHORNIMPORT_TIME_LIMIT';
    public const IMAGE_WORKER_LIMIT = 'MATTERHORNIMPORT_IMAGE_WORKER_LIMIT';
    public const IMAGE_WORKER_RUNTIME = 'MATTERHORNIMPORT_IMAGE_WORKER_RUNTIME';
    public const NEW_PRODUCT_WORKER_LIMIT = 'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_LIMIT';
    public const NEW_PRODUCT_WORKER_RUNTIME = 'MATTERHORNIMPORT_NEW_PRODUCT_WORKER_RUNTIME';
    public const RETRY_LIMIT = 'MATTERHORNIMPORT_RETRY_LIMIT';

    private const SPECS = [
        self::BATCH_SIZE => [500,1,10000],
        self::MAX_ITEMS => [0,0,1000000000],
        self::TIME_LIMIT => [0,0,86400],
        self::IMAGE_WORKER_LIMIT => [20,1,500],
        self::IMAGE_WORKER_RUNTIME => [0,0,86400],
        self::NEW_PRODUCT_WORKER_LIMIT => [20,1,200],
        self::NEW_PRODUCT_WORKER_RUNTIME => [0,0,86400],
        self::RETRY_LIMIT => [1000,1,100000],
    ];

    public function values(int $shopId): array
    {
        $values = [];
        foreach (self::SPECS as $key => [$default,$min,$max]) { $values[$key] = $this->int($shopId,$key,$default,$min,$max); }
        return $values;
    }

    public function inspect(int $shopId): array
    {
        $groupId = $this->shopGroupId($shopId);
        $result = [];
        foreach (self::SPECS as $key => [$default,$min,$max]) {
            $stored = \Configuration::get($key, null, $groupId, $shopId);
            $missing = $stored === false || $stored === null || $stored === '';
            $raw = $missing ? null : (string) $stored;
            $parsed = $missing ? $default : filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
            $valid = $missing || $parsed !== false;
            $result[$key] = ['raw'=>$raw,'effective'=>$valid?(int)$parsed:$default,'valid'=>$valid,'uses_default'=>$missing||!$valid,'min'=>$min,'max'=>$max];
        }
        return $result;
    }

    public function validate(array $raw): array
    {
        $values = [];
        foreach (self::SPECS as $key => [$default,$min,$max]) {
            $candidate = array_key_exists($key, $raw) ? $raw[$key] : $default;
            if (!is_scalar($candidate)) { throw new \InvalidArgumentException($key . ' must be an integer'); }
            $value = filter_var(trim((string)$candidate), FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($value === false) { throw new \InvalidArgumentException(sprintf('%s must be an integer from %d to %d',$key,$min,$max)); }
            $values[$key] = (int) $value;
        }
        return $values;
    }

    public function save(int $shopId, int $shopGroupId, array $raw): void
    {
        if ($shopId <= 0 || $shopGroupId <= 0) { throw new \InvalidArgumentException('Operational settings require concrete shop context'); }
        if ($this->shopGroupId($shopId) !== $shopGroupId) { throw new \RuntimeException('Operational settings shop/group mismatch'); }
        foreach ($this->validate($raw) as $key => $value) {
            if (!\Configuration::updateValue($key, (string)$value, false, $shopGroupId, $shopId)) { throw new \RuntimeException('Could not persist operational setting: ' . $key); }
        }
    }

    public function batchSize(int $shopId): int { return $this->value($shopId,self::BATCH_SIZE); }
    public function maxItems(int $shopId): int { return $this->value($shopId,self::MAX_ITEMS); }
    public function timeLimit(int $shopId): int { return $this->value($shopId,self::TIME_LIMIT); }
    public function imageWorkerLimit(int $shopId): int { return $this->value($shopId,self::IMAGE_WORKER_LIMIT); }
    public function imageWorkerRuntime(int $shopId): int { return $this->value($shopId,self::IMAGE_WORKER_RUNTIME); }
    public function newProductWorkerLimit(int $shopId): int { return $this->value($shopId,self::NEW_PRODUCT_WORKER_LIMIT); }
    public function newProductWorkerRuntime(int $shopId): int { return $this->value($shopId,self::NEW_PRODUCT_WORKER_RUNTIME); }
    public function retryLimit(int $shopId): int { return $this->value($shopId,self::RETRY_LIMIT); }

    private function value(int $shopId, string $key): int
    {
        [$default,$min,$max] = self::SPECS[$key];
        return $this->int($shopId,$key,$default,$min,$max);
    }

    private function int(int $shopId, string $key, int $default, int $min, int $max): int
    {
        if ($shopId <= 0) { throw new \InvalidArgumentException('Operational settings require a positive shop ID'); }
        $groupId = $this->shopGroupId($shopId);
        $raw = \Configuration::get($key, null, $groupId, $shopId);
        if ($raw === false || $raw === null || $raw === '') { return $default; }
        $value = filter_var((string)$raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
        return $value === false ? $default : (int) $value;
    }

    private function shopGroupId(int $shopId): int
    {
        $groupId = \Db::getInstance()->getValue('SELECT id_shop_group FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop=' . $shopId);
        if ($groupId === false || (int)$groupId <= 0) { throw new \RuntimeException('Could not resolve shop group for operational settings: ' . $shopId); }
        return (int) $groupId;
    }
}
