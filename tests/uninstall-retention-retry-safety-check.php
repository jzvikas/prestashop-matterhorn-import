<?php
declare(strict_types=1);

const _DB_PREFIX_ = 'ps_';

final class Configuration
{
    public static mixed $value = false;
    /** @var list<string> */
    public static array $deleted = [];

    public static function get(string $key, mixed $idLang = null, int $idShopGroup = 0, int $idShop = 0): mixed
    {
        return self::$value;
    }

    public static function deleteByName(string $key): bool
    {
        self::$deleted[] = $key;
        return true;
    }
}

final class Db
{
    public static int $executeCalls = 0;
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function execute(string $sql): bool
    {
        self::$executeCalls++;
        return true;
    }
}

require_once dirname(__DIR__) . '/src/Installer.php';

use Lp\MatterhornImport\Installer;

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Installer.php');
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([
    "private const RETAIN_DATA_KEY = 'MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL'",
    'self::RETAIN_DATA_KEY,',
    '$retentionSetting = \\Configuration::get(self::RETAIN_DATA_KEY, null, 0, 0);',
    "(string) \$retentionSetting !== '0'",
    'if (!$retainData && !$this->uninstallSchemaOnly())',
] as $token) {
    if (!str_contains($source, $token)) {
        $fail('missing uninstall retention safety invariant: ' . $token);
    }
}

$uninstallStart = strpos($source, 'public function uninstall(): bool');
$schemaStart = strpos($source, 'private function uninstallSchemaOnly()', $uninstallStart === false ? 0 : $uninstallStart);
if ($uninstallStart === false || $schemaStart === false) {
    $fail('could not isolate Installer::uninstall');
}
$uninstall = substr($source, $uninstallStart, $schemaStart - $uninstallStart);
$policyPos = strpos($uninstall, 'if (!$retainData && !$this->uninstallSchemaOnly())');
$cleanupPos = strpos($uninstall, 'foreach (self::CONFIG_KEYS as $key)');
if ($policyPos === false || $cleanupPos === false || $policyPos > $cleanupPos) {
    $fail('configuration cleanup can run before retention/schema guard');
}

$installer = new Installer();
foreach ([false, null, '', '1'] as $retainedValue) {
    Configuration::$value = $retainedValue;
    Configuration::$deleted = [];
    Db::$executeCalls = 0;

    if (!$installer->uninstall()) {
        $fail('retained/missing uninstall policy unexpectedly failed');
    }
    if (Db::$executeCalls !== 0) {
        $fail('retained/missing uninstall policy touched destructive schema SQL');
    }
    if (Configuration::$deleted === []) {
        $fail('retained uninstall did not clean managed configuration');
    }
}

Configuration::$value = '0';
Configuration::$deleted = [];
Db::$executeCalls = 0;
if (!$installer->uninstall()) {
    $fail('explicit destructive uninstall policy unexpectedly failed');
}
if (Db::$executeCalls <= 0) {
    $fail('explicit retention=0 did not execute destructive schema SQL');
}
if (Configuration::$deleted === []) {
    $fail('destructive uninstall did not clean managed configuration');
}

echo "UNINSTALL_RETENTION_RETRY_SAFETY_CHECK_OK\n";
