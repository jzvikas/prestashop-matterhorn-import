<?php

declare(strict_types=1);

$host = getenv('LP_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('LP_DB_PORT') ?: 3306);
$user = getenv('LP_DB_USER') ?: 'root';
$pass = getenv('LP_DB_PASSWORD') ?: 'root';
$name = getenv('LP_DB_NAME') ?: 'matterhorn_test';
$prefix = 'mh_cancel_';

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
    public function getRow(string $sql, bool $useCache = true): array|false
    {
        $result = $this->db->query($sql);
        if ($result === false) { throw new RuntimeException('getRow failed: ' . $this->db->error . ' SQL=' . $sql); }
        $row = $result->fetch_assoc();
        return is_array($row) ? $row : false;
    }
    public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
    {
        $result = $this->db->query($sql);
        if ($result === false) { return false; }
        $rows = [];
        while ($row = $result->fetch_assoc()) { $rows[] = $row; }
        return $rows;
    }
    public function getMsgError(): string { return $this->db->error; }
    public function update(string $table, array $data, string $where = '', int $limit = 0, bool $nullValues = false): bool
    {
        $sets = [];
        foreach ($data as $column => $value) {
            $quoted = '`' . str_replace('`', '', (string) $column) . '`';
            if ($value === null) { $sets[] = $quoted . '=NULL'; }
            elseif (is_int($value) || is_float($value)) { $sets[] = $quoted . '=' . $value; }
            else { $sets[] = $quoted . "='" . $this->escape((string) $value) . "'"; }
        }
        $sql = 'UPDATE `' . _DB_PREFIX_ . $table . '` SET ' . implode(',', $sets)
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ($limit > 0 ? ' LIMIT ' . $limit : '');
        return $this->execute($sql);
    }
    public function execute(string $sql): bool
    {
        $ok = $this->db->query($sql);
        $this->affected = $ok === false ? 0 : $this->db->affected_rows;
        return $ok !== false;
    }
    public function Affected_Rows(): int { return $this->affected; }
}

Db::bootstrap($mysqli);
$table = $prefix . 'li_matterhornim_99dfbf_run';

try {
    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
    if (!$mysqli->query(
        "CREATE TABLE `{$table}` (" .
        "id_run INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY," .
        "id_shop INT UNSIGNED NOT NULL," .
        "source VARCHAR(64) NOT NULL," .
        "status VARCHAR(16) NOT NULL," .
        "read_status VARCHAR(16) NOT NULL DEFAULT 'pending'," .
        "import_status VARCHAR(16) NOT NULL DEFAULT 'pending'," .
        "update_status VARCHAR(16) NOT NULL DEFAULT 'pending'," .
        "remove_status VARCHAR(16) NOT NULL DEFAULT 'pending'," .
        "import_done INT UNSIGNED NOT NULL DEFAULT 0," .
        "finished_at DATETIME NULL" .
        ") ENGINE=InnoDB"
    )) { throw new RuntimeException('Could not create cancellation fixture table: ' . $mysqli->error); }

    if (!$mysqli->query(
        "INSERT INTO `{$table}` (id_shop,source,status,read_status,import_status,update_status,remove_status) " .
        "VALUES (1,'matterhorn','running','completed','running','pending','pending')"
    )) { throw new RuntimeException('Could not insert cancellation fixture'); }

    require_once dirname(__DIR__) . '/src/Repository/RunRepository.php';
    $runs = new \Lp\MatterhornImport\Repository\RunRepository();
    $cancelled = $runs->cancel(1);
    if (($cancelled['status'] ?? null) !== 'cancelled' || ($cancelled['import_status'] ?? null) !== 'paused') {
        throw new RuntimeException('Cancellation did not persist terminal/paused stage state');
    }

    $finishBlocked = false;
    try { $runs->finish(1, 'completed'); }
    catch (RuntimeException) { $finishBlocked = true; }
    if (!$finishBlocked) { throw new RuntimeException('Cancelled run was resurrected as completed'); }

    $stageBlocked = false;
    try { $runs->stage(1, 'import', 'completed'); }
    catch (RuntimeException) { $stageBlocked = true; }
    if (!$stageBlocked) { throw new RuntimeException('Cancelled run accepted stale stage mutation'); }

    $counterBlocked = false;
    try { $runs->increment(1, 'import_done', 1); }
    catch (RuntimeException) { $counterBlocked = true; }
    if (!$counterBlocked) { throw new RuntimeException('Cancelled run accepted stale counter mutation'); }

    $row = $mysqli->query("SELECT status,import_status,import_done FROM `{$table}` WHERE id_run=1")?->fetch_assoc();
    if (!is_array($row) || $row['status'] !== 'cancelled' || $row['import_status'] !== 'paused' || (int) $row['import_done'] !== 0) {
        throw new RuntimeException('Cancelled run terminal state was mutated by stale worker operations');
    }

    if (!$mysqli->query(
        "INSERT INTO `{$table}` (id_shop,source,status,read_status,import_status,update_status,remove_status) " .
        "VALUES (1,'matterhorn','running','completed','running','pending','pending')"
    )) { throw new RuntimeException('Could not insert run-lock fixture'); }

    if (!$mysqli->begin_transaction()) { throw new RuntimeException('Could not start run-lock fixture transaction'); }
    $runs->lockRunning(2);
    $mysqli->rollback();
    if (!$mysqli->query("UPDATE `{$table}` SET status='cancelled' WHERE id_run=2")) {
        throw new RuntimeException('Could not cancel run-lock fixture');
    }
    if (!$mysqli->begin_transaction()) { throw new RuntimeException('Could not restart run-lock fixture transaction'); }
    $lockBlocked = false;
    try { $runs->lockRunning(2); }
    catch (RuntimeException) { $lockBlocked = true; }
    $mysqli->rollback();
    if (!$lockBlocked) { throw new RuntimeException('lockRunning allowed cancelled worker execution'); }

    echo "Matterhorn cancelled-run MariaDB fence: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
    $mysqli->close();
}
