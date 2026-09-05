<?php
declare(strict_types=1);

function featureSemanticFail(string $message): never
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
define('_DB_PREFIX_', $prefix);

final class Db
{
    private static ?self $instance = null;
    private mysqli $db;

    public function __construct(string $host, string $user, string $password, string $database, int $port)
    {
        $this->db = @new mysqli($host, $user, $password, $database, $port);
        if ($this->db->connect_errno) {
            featureSemanticFail('MariaDB connection failed: ' . $this->db->connect_error);
        }
        $this->db->set_charset('utf8mb4');
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Test Db is not initialized');
        }
        return self::$instance;
    }

    public function execute(string $sql): bool
    {
        return $this->db->query($sql) === true;
    }

    public function getRow(string $sql, bool $useCache = true): array|false
    {
        $result = $this->db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException($this->db->error . ' SQL=' . $sql);
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: false;
    }

    public function getValue(string $sql, bool $useCache = true): mixed
    {
        $result = $this->db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException($this->db->error . ' SQL=' . $sql);
        }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? false;
    }

    public function escape(string $value): string
    {
        return $this->db->real_escape_string($value);
    }

    public function raw(): mysqli
    {
        return $this->db;
    }
}

function pSQL(string $value, bool $htmlOK = false): string
{
    return Db::getInstance()->escape($value);
}

$db = new Db($host, $user, $password, $database, $port);
$mysqli = $db->raw();
$featureTable = $prefix . 'li_matterhornim_99dfbf_feature_mapping';
$valueTable = $prefix . 'li_matterhornim_99dfbf_feature_value_mapping';

$exec = static function (mysqli $mysqli, string $sql): void {
    if (!$mysqli->query($sql)) {
        featureSemanticFail($mysqli->error . ' SQL=' . $sql);
    }
};
$value = static function (mysqli $mysqli, string $sql): string {
    $result = $mysqli->query($sql);
    if (!$result instanceof mysqli_result) {
        featureSemanticFail($mysqli->error . ' SQL=' . $sql);
    }
    $row = $result->fetch_row();
    $result->free();
    return (string) ($row[0] ?? '');
};

require_once dirname(__DIR__) . '/src/Repository/FeatureMappingRepository.php';

use Lp\MatterhornImport\Repository\FeatureMappingRepository;

try {
    $exec($mysqli, "DROP TABLE IF EXISTS `{$valueTable}`");
    $exec($mysqli, "DROP TABLE IF EXISTS `{$featureTable}`");
    $exec($mysqli, "CREATE TABLE `{$featureTable}` (
        id_shop INT UNSIGNED NOT NULL,
        source VARCHAR(64) NOT NULL,
        supplier_feature_key VARCHAR(191) NOT NULL,
        supplier_name VARCHAR(128) NOT NULL,
        id_feature INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id_shop,source,supplier_feature_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $exec($mysqli, "CREATE TABLE `{$valueTable}` (
        id_shop INT UNSIGNED NOT NULL,
        source VARCHAR(64) NOT NULL,
        supplier_feature_key VARCHAR(191) NOT NULL,
        supplier_value_key VARCHAR(191) NOT NULL,
        supplier_value VARCHAR(255) NOT NULL,
        id_feature INT UNSIGNED NOT NULL,
        id_feature_value INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id_shop,source,supplier_feature_key,supplier_value_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $mapping = new FeatureMappingRepository();
    $featureKey = 'matterhorn:color';
    $valueKey = 'matterhorn:color:red-blue';

    $mapping->assertSemanticIdentity(1, 'matterhorn', $featureKey, 'Color', $valueKey, 'red/blue');
    $mapping->saveResolved(1, 'matterhorn', $featureKey, 'Color', $valueKey, 'red/blue', 11, 21);
    $mapping->assertSemanticIdentity(1, 'matterhorn', $featureKey, 'Color', $valueKey, 'red/blue');

    if ($value($mysqli, "SELECT supplier_value FROM `{$valueTable}` WHERE id_shop=1 AND source='matterhorn' AND supplier_feature_key='matterhorn:color' AND supplier_value_key='matterhorn:color:red-blue'") !== 'red/blue') {
        featureSemanticFail('initial supplier feature semantic identity was not persisted');
    }

    try {
        $mapping->assertSemanticIdentity(1, 'matterhorn', $featureKey, 'Color', $valueKey, 'red blue');
        featureSemanticFail('same value key with different supplier value must fail preflight');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Feature semantic identity collision')) {
            featureSemanticFail('unexpected preflight collision error: ' . $e->getMessage());
        }
    }

    $freshMapping = new FeatureMappingRepository();
    try {
        $freshMapping->saveResolved(1, 'matterhorn', $featureKey, 'Color', $valueKey, 'red blue', 12, 22);
        featureSemanticFail('fresh save must not overwrite an existing colliding semantic identity');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Feature semantic identity collision')) {
            featureSemanticFail('unexpected locked-save collision error: ' . $e->getMessage());
        }
    }
    if ($value($mysqli, "SELECT CONCAT(supplier_value,':',id_feature,':',id_feature_value) FROM `{$valueTable}` WHERE id_shop=1 AND source='matterhorn' AND supplier_feature_key='matterhorn:color' AND supplier_value_key='matterhorn:color:red-blue'") !== 'red/blue:11:21') {
        featureSemanticFail('colliding semantic save overwrote the existing mapping');
    }

    $nameCollision = new FeatureMappingRepository();
    try {
        $nameCollision->assertSemanticIdentity(1, 'matterhorn', $featureKey, 'Colour', $valueKey, 'red/blue');
        featureSemanticFail('same feature key with different supplier name must fail closed');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Feature semantic identity collision')) {
            featureSemanticFail('unexpected feature-name collision error: ' . $e->getMessage());
        }
    }

    $otherScope = new FeatureMappingRepository();
    $otherScope->saveResolved(2, 'matterhorn', $featureKey, 'Color', $valueKey, 'red blue', 12, 22);
    $otherScope->saveResolved(1, 'other-source', $featureKey, 'Color', $valueKey, 'red blue', 13, 23);
    if ($value($mysqli, "SELECT COUNT(*) FROM `{$valueTable}`") !== '3') {
        featureSemanticFail('semantic identity fence must remain scoped by shop and source');
    }

    $exec($mysqli, "INSERT INTO `{$valueTable}` (id_shop,source,supplier_feature_key,supplier_value_key,supplier_value,id_feature,id_feature_value,updated_at) VALUES (3,'matterhorn','matterhorn:type','matterhorn:type:a-b','A/B',31,41,NOW())");
    $orphanValueMapping = new FeatureMappingRepository();
    try {
        $orphanValueMapping->assertSemanticIdentity(3, 'matterhorn', 'matterhorn:type', 'Type', 'matterhorn:type:a-b', 'A B');
        featureSemanticFail('orphan value mapping collision must still be detected');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Feature semantic identity collision')) {
            featureSemanticFail('unexpected orphan-value collision error: ' . $e->getMessage());
        }
    }

    echo "Feature semantic collision MariaDB checks: OK\n";
} finally {
    @$mysqli->query("DROP TABLE IF EXISTS `{$valueTable}`");
    @$mysqli->query("DROP TABLE IF EXISTS `{$featureTable}`");
    $mysqli->close();
}
