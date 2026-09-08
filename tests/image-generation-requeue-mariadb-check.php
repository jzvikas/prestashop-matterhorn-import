<?php

declare(strict_types=1);

$host = getenv('LP_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('LP_DB_PORT') ?: 3306);
$user = getenv('LP_DB_USER') ?: 'root';
$pass = getenv('LP_DB_PASSWORD') ?: 'root';
$name = getenv('LP_DB_NAME') ?: 'matterhorn_test';
$prefix = 'mh_img_gen_';

$mysqli = new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "DB connect failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', $prefix); }
if (!function_exists('pSQL')) {
    function pSQL(string $value, bool $htmlOK = false): string
    {
        return Db::getInstance()->escape($value);
    }
}

final class Db
{
    private static ?self $instance = null;
    private int $affected = 0;

    public function __construct(private mysqli $db) {}
    public static function bootstrap(mysqli $db): void { self::$instance = new self($db); }
    public static function getInstance(): self
    {
        if (self::$instance === null) { throw new RuntimeException('Test Db not bootstrapped'); }
        return self::$instance;
    }
    public function escape(string $value): string { return $this->db->real_escape_string($value); }
    public function execute(string $sql): bool
    {
        $result = $this->db->query($sql);
        $this->affected = $result === false ? 0 : $this->db->affected_rows;
        return $result !== false;
    }
    public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
    {
        $result = $this->db->query($sql);
        if ($result === false) { return false; }
        $rows = [];
        while ($row = $result->fetch_assoc()) { $rows[] = $row; }
        return $rows;
    }
    public function getValue(string $sql, bool $useCache = true): mixed
    {
        $result = $this->db->query($sql);
        if ($result === false) { return false; }
        $row = $result->fetch_row();
        return $row[0] ?? false;
    }
    public function Affected_Rows(): int { return $this->affected; }
    public function getMsgError(): string { return $this->db->error; }
    public function delete(string $table, string $where = '', int $limit = 0, bool $useCache = true): bool
    {
        return $this->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '`' . ($where !== '' ? ' WHERE ' . $where : '') . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    }
}

Db::bootstrap($mysqli);
$table = $prefix . 'li_matterhornim_99dfbf_image_queue';

try {
    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
    if (!$mysqli->query(
        "CREATE TABLE `{$table}` (" .
        "id_queue BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY," .
        "id_run BIGINT UNSIGNED NOT NULL," .
        "id_shop INT UNSIGNED NOT NULL," .
        "source VARCHAR(64) NOT NULL," .
        "source_key VARCHAR(191) NOT NULL," .
        "id_product INT UNSIGNED NOT NULL," .
        "url TEXT NOT NULL," .
        "url_hash CHAR(64) NOT NULL," .
        "position SMALLINT UNSIGNED NOT NULL DEFAULT 0," .
        "is_cover TINYINT(1) NOT NULL DEFAULT 0," .
        "status VARCHAR(16) NOT NULL DEFAULT 'pending'," .
        "attempts TINYINT UNSIGNED NOT NULL DEFAULT 0," .
        "available_at DATETIME NULL," .
        "locked_by VARCHAR(64) NULL," .
        "locked_until DATETIME NULL," .
        "last_error TEXT NULL," .
        "created_at DATETIME NOT NULL," .
        "updated_at DATETIME NOT NULL," .
        "UNIQUE KEY uq_product_url (id_shop,id_product,url_hash)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )) { throw new RuntimeException('Could not create image generation fixture table: ' . $mysqli->error); }

    require_once dirname(__DIR__) . '/src/Util/DiagnosticMessageSanitizer.php';
    require_once dirname(__DIR__) . '/src/Repository/ImageQueueRepository.php';

    $queue = new \Lp\MatterhornImport\Repository\ImageQueueRepository(
        new \Lp\MatterhornImport\Util\DiagnosticMessageSanitizer()
    );

    $target = 'https://cdn.example.test/target.jpg';
    $other = 'https://cdn.example.test/other.jpg';
    $queue->enqueue(10, 1, 'matterhorn', 'SKU-1', 100, [$target, $other]);

    $claimed = $queue->claim('old-worker', 'matterhorn', 1, 1);
    if (count($claimed) !== 1) { throw new RuntimeException('Expected one claimed image row'); }
    $row = $claimed[0];
    if ((int) $row['id_run'] !== 10 || (int) $row['position'] !== 0 || (int) $row['is_cover'] !== 1) {
        throw new RuntimeException('Old generation claim fixture is invalid');
    }
    $idQueue = (int) $row['id_queue'];
    $token = (string) $row['locked_by'];

    // Model the exact hook-commit race: while the stale worker has lost its FOR UPDATE lock,
    // a newer authoritative manifest keeps the same URL/owner but advances run + placement.
    $queue->enqueue(11, 1, 'matterhorn', 'SKU-1', 100, [$other, $target]);

    $advanced = $mysqli->query("SELECT id_run,position,is_cover,status,attempts,locked_by FROM `{$table}` WHERE id_queue={$idQueue}")?->fetch_assoc();
    if (!is_array($advanced)
        || (int) $advanced['id_run'] !== 11
        || (int) $advanced['position'] !== 1
        || (int) $advanced['is_cover'] !== 0
        || $advanced['status'] !== 'processing'
        || (string) $advanced['locked_by'] !== $token
    ) {
        throw new RuntimeException('Newer same-owner image generation did not advance under the stale lease');
    }

    $appliedToOldGeneration = $queue->fail(
        $idQueue,
        $token,
        'stale worker detected newer generation after hook commit',
        true,
        10
    );
    if ($appliedToOldGeneration) {
        throw new RuntimeException('Stale image worker incorrectly finalized failure against the old generation');
    }

    $requeued = $mysqli->query("SELECT id_run,position,is_cover,status,attempts,locked_by,locked_until,last_error FROM `{$table}` WHERE id_queue={$idQueue}")?->fetch_assoc();
    if (!is_array($requeued)
        || (int) $requeued['id_run'] !== 11
        || (int) $requeued['position'] !== 1
        || (int) $requeued['is_cover'] !== 0
        || $requeued['status'] !== 'pending'
        || (int) $requeued['attempts'] !== 0
        || $requeued['locked_by'] !== null
        || $requeued['locked_until'] !== null
        || $requeued['last_error'] !== null
    ) {
        throw new RuntimeException('Newer image generation was not cleanly requeued after stale-worker fencing');
    }

    echo "Matterhorn image generation requeue MariaDB fence: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
    $mysqli->close();
}
