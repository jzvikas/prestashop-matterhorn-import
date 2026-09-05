<?php
declare(strict_types=1);

function imageSqlFail(string $message): never
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
    imageSqlFail('MariaDB connection failed: ' . $db->connect_error);
}
$db->set_charset('utf8mb4');

$imageTable = $prefix . 'image_race_image';
$imageShopTable = $prefix . 'image_race_image_shop';

$exec = static function (mysqli $db, string $sql): void {
    if (!$db->query($sql)) {
        imageSqlFail($db->error . ' SQL=' . $sql);
    }
};
$value = static function (mysqli $db, string $sql): ?string {
    $result = $db->query($sql);
    if (!$result) { imageSqlFail($db->error . ' SQL=' . $sql); }
    $row = $result->fetch_row();
    $result->free();
    return $row === null ? null : (string) $row[0];
};

$setTargetCover = static function (mysqli $db, string $table, int $productId, int $shopId, int $coverImageId) use ($exec, $value): void {
    $sql = sprintf(
        'UPDATE `%1$s` current_cover INNER JOIN `%1$s` replacement ' .
        'ON replacement.id_image=%2$d AND replacement.id_product=%3$d AND replacement.id_shop=%4$d ' .
        'SET current_cover.cover=CASE WHEN current_cover.id_image=%2$d THEN 1 ELSE NULL END ' .
        'WHERE current_cover.id_product=%3$d AND current_cover.id_shop=%4$d ' .
        'AND (current_cover.cover=1 OR current_cover.id_image=%2$d)',
        $table,
        $coverImageId,
        $productId,
        $shopId
    );
    $exec($db, $sql);
    $count = (int) ($value($db, sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE id_product=%d AND id_shop=%d AND cover=1',
        $table,
        $productId,
        $shopId
    )) ?? '0');
    $cover = (int) ($value($db, sprintf(
        'SELECT COALESCE(MAX(id_image),0) FROM `%s` WHERE id_product=%d AND id_shop=%d AND cover=1',
        $table,
        $productId,
        $shopId
    )) ?? '0');
    if ($count !== 1 || $cover !== $coverImageId) {
        imageSqlFail('target-shop cover atomic update mismatch');
    }
};

$transferGlobalCover = static function (
    mysqli $db,
    string $imageTable,
    string $imageShopTable,
    int $oldImageId,
    int $newImageId,
    int $productId,
    int $shopId
) use ($exec): void {
    $sql = sprintf(
        'UPDATE `%1$s` i ' .
        'INNER JOIN `%1$s` old_cover ON old_cover.id_image=%2$d AND old_cover.id_product=i.id_product AND old_cover.cover=1 ' .
        'INNER JOIN `%1$s` replacement_image ON replacement_image.id_image=%3$d AND replacement_image.id_product=i.id_product ' .
        'INNER JOIN `%4$s` old_target ON old_target.id_image=old_cover.id_image AND old_target.id_product=i.id_product AND old_target.id_shop=%5$d ' .
        'INNER JOIN `%4$s` new_target ON new_target.id_image=replacement_image.id_image AND new_target.id_product=i.id_product AND new_target.id_shop=%5$d ' .
        'LEFT JOIN `%4$s` old_other ON old_other.id_image=old_cover.id_image AND old_other.id_product=i.id_product AND old_other.id_shop<>%5$d ' .
        'SET i.cover=CASE WHEN i.id_image=%3$d THEN 1 ELSE NULL END ' .
        'WHERE i.id_product=%6$d AND i.id_image IN (%2$d,%3$d) AND old_other.id_image IS NULL',
        $imageTable,
        $oldImageId,
        $newImageId,
        $imageShopTable,
        $shopId,
        $productId
    );
    $exec($db, $sql);
};

$setExclusivePosition = static function (
    mysqli $db,
    string $imageTable,
    string $imageShopTable,
    int $imageId,
    int $productId,
    int $shopId,
    int $position
) use ($exec): void {
    $sql = sprintf(
        'UPDATE `%1$s` i ' .
        'INNER JOIN `%2$s` target ON target.id_image=i.id_image AND target.id_product=i.id_product AND target.id_shop=%3$d ' .
        'LEFT JOIN `%2$s` other ON other.id_image=i.id_image AND other.id_product=i.id_product AND other.id_shop<>%3$d ' .
        'SET i.position=%4$d WHERE i.id_image=%5$d AND i.id_product=%6$d AND other.id_image IS NULL',
        $imageTable,
        $imageShopTable,
        $shopId,
        $position,
        $imageId,
        $productId
    );
    $exec($db, $sql);
};

try {
    $exec($db, "DROP TABLE IF EXISTS `{$imageShopTable}`");
    $exec($db, "DROP TABLE IF EXISTS `{$imageTable}`");
    $exec($db, "CREATE TABLE `{$imageTable}` (
        id_image INT UNSIGNED NOT NULL,
        id_product INT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        cover TINYINT(1) NULL DEFAULT NULL,
        PRIMARY KEY (id_image),
        KEY idx_product (id_product)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $exec($db, "CREATE TABLE `{$imageShopTable}` (
        id_product INT UNSIGNED NOT NULL,
        id_image INT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        cover TINYINT(1) NULL DEFAULT NULL,
        PRIMARY KEY (id_image,id_shop),
        KEY idx_product_shop_cover (id_product,id_shop,cover)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $exec($db, "INSERT INTO `{$imageTable}` (id_image,id_product,position,cover) VALUES
        (1,10,1,1),(2,10,2,NULL),(3,10,3,1),(4,10,4,NULL),(5,10,9,NULL),(6,10,11,NULL)");
    $exec($db, "INSERT INTO `{$imageShopTable}` (id_product,id_image,id_shop,cover) VALUES
        (10,1,1,1),(10,2,1,NULL),
        (10,3,1,1),(10,3,2,1),(10,4,1,NULL),
        (10,5,1,NULL),(10,6,1,NULL),(10,6,2,NULL)");

    $setTargetCover($db, $imageShopTable, 10, 1, 2);
    $transferGlobalCover($db, $imageTable, $imageShopTable, 1, 2, 10, 1);
    if ((int) ($value($db, "SELECT COALESCE(cover,0) FROM `{$imageTable}` WHERE id_image=1") ?? '0') !== 0
        || (int) ($value($db, "SELECT COALESCE(cover,0) FROM `{$imageTable}` WHERE id_image=2") ?? '0') !== 1) {
        imageSqlFail('exclusive old global cover was not transferred to replacement');
    }

    // A shared old image may move the target-shop cover, but its global legacy cover
    // must remain untouched because another shop still owns the same image association.
    $exec($db, "UPDATE `{$imageShopTable}` SET cover=NULL WHERE id_product=10 AND id_shop=1");
    $exec($db, "UPDATE `{$imageShopTable}` SET cover=1 WHERE id_image=3 AND id_shop=1");
    $exec($db, "UPDATE `{$imageTable}` SET cover=NULL WHERE id_product=10");
    $exec($db, "UPDATE `{$imageTable}` SET cover=1 WHERE id_image=3");
    $setTargetCover($db, $imageShopTable, 10, 1, 4);
    $transferGlobalCover($db, $imageTable, $imageShopTable, 3, 4, 10, 1);
    if ((int) ($value($db, "SELECT COALESCE(cover,0) FROM `{$imageTable}` WHERE id_image=3") ?? '0') !== 1
        || (int) ($value($db, "SELECT COALESCE(cover,0) FROM `{$imageTable}` WHERE id_image=4") ?? '0') !== 0) {
        imageSqlFail('shared old image incorrectly changed global cover shadow');
    }

    $setExclusivePosition($db, $imageTable, $imageShopTable, 5, 10, 1, 1);
    $setExclusivePosition($db, $imageTable, $imageShopTable, 6, 10, 1, 2);
    if ((int) ($value($db, "SELECT position FROM `{$imageTable}` WHERE id_image=5") ?? '0') !== 1) {
        imageSqlFail('exclusive image position was not updated');
    }
    if ((int) ($value($db, "SELECT position FROM `{$imageTable}` WHERE id_image=6") ?? '0') !== 11) {
        imageSqlFail('shared image global position was overwritten');
    }

    echo "Image placement MariaDB race checks: OK\n";
} finally {
    @$db->query("DROP TABLE IF EXISTS `{$imageShopTable}`");
    @$db->query("DROP TABLE IF EXISTS `{$imageTable}`");
    $db->close();
}
