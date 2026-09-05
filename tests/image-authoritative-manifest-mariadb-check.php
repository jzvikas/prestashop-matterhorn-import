<?php

declare(strict_types=1);

function imageManifestSqlFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$host = getenv('LP_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('LP_DB_PORT') ?: '3306');
$user = getenv('LP_DB_USER') ?: 'root';
$password = getenv('LP_DB_PASSWORD') ?: '';
$database = getenv('LP_DB_NAME') ?: 'matterhorn_test';
$prefix = preg_replace('/[^A-Za-z0-9_]/', '', getenv('LP_DB_PREFIX') ?: 'mh_') ?: 'mh_';

$db = @new mysqli($host, $user, $password, $database, $port);
if ($db->connect_errno) {
    imageManifestSqlFail('MariaDB connection failed: ' . $db->connect_error);
}
$db->set_charset('utf8mb4');

$table = $prefix . 'image_manifest_fence_runtime';
$exec = static function (mysqli $db, string $sql): void {
    if (!$db->query($sql)) {
        imageManifestSqlFail($db->error . ' SQL=' . $sql);
    }
};
$row = static function (mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) { imageManifestSqlFail($db->error . ' SQL=' . $sql); }
    $value = $result->fetch_assoc();
    $result->free();
    return is_array($value) ? $value : [];
};

try {
    $exec($db, "DROP TABLE IF EXISTS `{$table}`");
    $exec($db, "CREATE TABLE `{$table}` (
        id_queue BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_run BIGINT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        source VARCHAR(64) NOT NULL,
        source_key VARCHAR(191) NOT NULL,
        id_product INT UNSIGNED NOT NULL,
        url_hash CHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL,
        available_at DATETIME NULL,
        locked_by VARCHAR(64) NULL,
        locked_until DATETIME NULL,
        last_error TEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id_queue),
        UNIQUE KEY uq_product_url (id_shop,id_product,url_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hash = static fn(string $value): string => hash('sha256', $value);
    $values = [
        [10,1,'matterhorn','sku-1',1001,$hash('old-pending'),'pending',null,null],
        [10,1,'matterhorn','sku-1',1001,$hash('old-processing'),'processing','worker:old','2099-01-01 00:00:00'],
        [10,1,'matterhorn','sku-1',1001,$hash('old-failed'),'failed',null,null],
        [11,1,'matterhorn','sku-1',1001,$hash('current-desired'),'pending',null,null],
        [12,1,'matterhorn','sku-1',1001,$hash('newer-generation'),'pending',null,null],
        [10,1,'other-source','sku-1',1001,$hash('foreign-source'),'pending',null,null],
        [10,1,'matterhorn','sku-other',1001,$hash('foreign-key'),'pending',null,null],
        [10,1,'matterhorn','sku-1',1002,$hash('foreign-product'),'pending',null,null],
    ];
    foreach ($values as [$runId,$shopId,$source,$sourceKey,$productId,$urlHash,$status,$lockedBy,$lockedUntil]) {
        $sourceSql = $db->real_escape_string($source);
        $keySql = $db->real_escape_string($sourceKey);
        $hashSql = $db->real_escape_string($urlHash);
        $statusSql = $db->real_escape_string($status);
        $lockedBySql = $lockedBy === null ? 'NULL' : "'" . $db->real_escape_string($lockedBy) . "'";
        $lockedUntilSql = $lockedUntil === null ? 'NULL' : "'" . $db->real_escape_string($lockedUntil) . "'";
        $exec($db, "INSERT INTO `{$table}` (id_run,id_shop,source,source_key,id_product,url_hash,status,available_at,locked_by,locked_until,last_error,updated_at) VALUES ({$runId},{$shopId},'{$sourceSql}','{$keySql}',{$productId},'{$hashSql}','{$statusSql}',NULL,{$lockedBySql},{$lockedUntilSql},NULL,NOW())");
    }

    $reason = $db->real_escape_string('superseded: removed from newer authoritative image manifest');
    $exec($db, "UPDATE `{$table}` SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL,last_error='{$reason}',updated_at=NOW() WHERE id_shop=1 AND source='matterhorn' AND source_key='sku-1' AND id_product=1001 AND id_run<11 AND status IN ('pending','processing','failed')");

    if ($db->affected_rows !== 3) {
        imageManifestSqlFail('authoritative manifest fence did not supersede exactly three older unresolved owner rows');
    }

    foreach (['old-pending','old-processing','old-failed'] as $name) {
        $state = $row($db, "SELECT status,locked_by,locked_until,last_error FROM `{$table}` WHERE url_hash='" . $db->real_escape_string($hash($name)) . "'");
        if (($state['status'] ?? '') !== 'done' || $state['locked_by'] !== null || $state['locked_until'] !== null) {
            imageManifestSqlFail('older unresolved row retained active state for ' . $name);
        }
        if (!str_contains((string) ($state['last_error'] ?? ''), 'removed from newer authoritative image manifest')) {
            imageManifestSqlFail('older unresolved row lost supersede reason for ' . $name);
        }
    }

    foreach (['current-desired','newer-generation','foreign-source','foreign-key','foreign-product'] as $name) {
        $state = $row($db, "SELECT status,last_error FROM `{$table}` WHERE url_hash='" . $db->real_escape_string($hash($name)) . "'");
        if (($state['status'] ?? '') !== 'pending' || ($state['last_error'] ?? null) !== null) {
            imageManifestSqlFail('manifest fence touched non-target row ' . $name);
        }
    }

    echo "Authoritative image manifest MariaDB fence checks: OK\n";
} finally {
    @$db->query("DROP TABLE IF EXISTS `{$table}`");
    $db->close();
}
