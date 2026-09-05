<?php
declare(strict_types=1);

function outOfFeedFenceFail(string $message): never
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
    outOfFeedFenceFail('MariaDB connection failed: ' . $db->connect_error);
}
$db->set_charset('utf8mb4');

$mapping = $prefix . 'image_active_mapping';
$queue = $prefix . 'image_active_queue';

$exec = static function (mysqli $db, string $sql): void {
    if (!$db->query($sql)) {
        outOfFeedFenceFail($db->error . ' SQL=' . $sql);
    }
};
$value = static function (mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) { outOfFeedFenceFail($db->error . ' SQL=' . $sql); }
    $row = $result->fetch_row();
    $result->free();
    return $row === null ? 0 : (int) $row[0];
};
$countActive = static function (mysqli $db, string $queue, string $mapping, int $shopId, string $source, ?int $runId = null) use ($value): int {
    $runWhere = $runId === null ? '' : ' AND q.id_run=' . $runId;
    return $value($db,
        'SELECT COUNT(*) FROM `' . $queue . '` q ' .
        'INNER JOIN `' . $mapping . '` m ON m.id_shop=q.id_shop AND m.source=q.source ' .
        'AND m.source_key=q.source_key AND m.id_product=q.id_product AND m.out_of_feed=0 ' .
        "WHERE q.id_shop={$shopId} AND q.source='" . $db->real_escape_string($source) . "' AND q.status<>'done'" . $runWhere
    );
};
$ownsActive = static function (mysqli $db, string $mapping, int $shopId, string $source, string $sourceKey, int $productId) use ($value): bool {
    return $value($db,
        'SELECT COUNT(*) FROM `' . $mapping . '` WHERE id_shop=' . $shopId .
        " AND source='" . $db->real_escape_string($source) . "'" .
        " AND source_key='" . $db->real_escape_string($sourceKey) . "'" .
        ' AND id_product=' . $productId . ' AND out_of_feed=0'
    ) === 1;
};

try {
    $exec($db, "DROP TABLE IF EXISTS `{$queue}`");
    $exec($db, "DROP TABLE IF EXISTS `{$mapping}`");
    $exec($db, "CREATE TABLE `{$mapping}` (
        id_shop INT UNSIGNED NOT NULL,
        source VARCHAR(64) NOT NULL,
        source_key VARCHAR(191) NOT NULL,
        id_product INT UNSIGNED NOT NULL,
        out_of_feed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id_shop,source,source_key),
        KEY idx_product (id_shop,id_product)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $exec($db, "CREATE TABLE `{$queue}` (
        id_queue BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_run BIGINT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        source VARCHAR(64) NOT NULL,
        source_key VARCHAR(191) NOT NULL,
        id_product INT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        PRIMARY KEY (id_queue),
        KEY idx_scope (id_shop,source,status,id_run)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $exec($db, "INSERT INTO `{$mapping}` (id_shop,source,source_key,id_product,out_of_feed) VALUES
        (1,'matterhorn','active',101,0),
        (1,'matterhorn','removed',202,1),
        (1,'other','foreign',303,0),
        (2,'matterhorn','other-shop',404,0)");
    $exec($db, "INSERT INTO `{$queue}` (id_run,id_shop,source,source_key,id_product,status) VALUES
        (10,1,'matterhorn','active',101,'pending'),
        (10,1,'matterhorn','removed',202,'processing'),
        (10,1,'other','foreign',303,'pending'),
        (10,2,'matterhorn','other-shop',404,'pending'),
        (10,1,'matterhorn','active',999,'pending'),
        (11,1,'matterhorn','active',101,'done')");

    if (!$ownsActive($db, $mapping, 1, 'matterhorn', 'active', 101)) {
        outOfFeedFenceFail('active exact mapping was not accepted');
    }
    if ($ownsActive($db, $mapping, 1, 'matterhorn', 'removed', 202)) {
        outOfFeedFenceFail('out-of-feed mapping was incorrectly accepted as active');
    }
    if ($countActive($db, $queue, $mapping, 1, 'matterhorn', 10) !== 1) {
        outOfFeedFenceFail('current-run active unresolved count must exclude out-of-feed/foreign/mismatched rows');
    }
    if ($countActive($db, $queue, $mapping, 1, 'matterhorn') !== 1) {
        outOfFeedFenceFail('source active unresolved count must exclude out-of-feed/foreign/mismatched rows');
    }

    $exec($db, "UPDATE `{$queue}` SET status='done' WHERE id_shop=1 AND source='matterhorn' AND source_key='active' AND id_product=101 AND id_run=10");
    if ($countActive($db, $queue, $mapping, 1, 'matterhorn', 10) !== 0
        || $countActive($db, $queue, $mapping, 1, 'matterhorn') !== 0) {
        outOfFeedFenceFail('retained unresolved out-of-feed job still blocked active reconciliation');
    }

    echo "Image out-of-feed MariaDB fence checks: OK\n";
} finally {
    @$db->query("DROP TABLE IF EXISTS `{$queue}`");
    @$db->query("DROP TABLE IF EXISTS `{$mapping}`");
    $db->close();
}
