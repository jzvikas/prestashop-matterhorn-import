<?php
declare(strict_types=1);

if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'ps_'); }

final class Db
{
    private static ?self $instance = null;
    /** @var list<array<string,int>>|false */
    public array|false $rows = [];
    /** @var list<array{sql:string,use_cache:bool}> */
    public array $calls = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
    {
        $this->calls[] = ['sql' => $sql, 'use_cache' => $useCache];
        return $this->rows;
    }
}

final class Configuration
{
    public static function get(string $key, mixed $idLang = null, mixed $idShopGroup = null, mixed $idShop = null): string|false
    {
        return $key === 'PS_ROOT_CATEGORY' ? '1' : false;
    }
}

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Category\CategorySynchronizer;

function hierarchyFenceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionClass(CategorySynchronizer::class);
$sync = $reflection->newInstanceWithoutConstructor();
$expand = $reflection->getMethod('expandHierarchy');
$expand->setAccessible(true);
$db = Db::getInstance();

$db->rows = [
    ['leaf_id' => 10, 'id_category' => 2],
    ['leaf_id' => 10, 'id_category' => 3],
    ['leaf_id' => 10, 'id_category' => 10],
];
$first = $expand->invoke($sync, [10], 2);
hierarchyFenceCheck($first === [2, 3, 10], 'initial live hierarchy must include target-shop ancestors and leaf');

$db->rows = [
    ['leaf_id' => 10, 'id_category' => 2],
    ['leaf_id' => 10, 'id_category' => 4],
    ['leaf_id' => 10, 'id_category' => 10],
];
$second = $expand->invoke($sync, [10], 2);
hierarchyFenceCheck($second === [2, 4, 10], 'same process cache key must observe a live category reparent');
hierarchyFenceCheck(count($db->calls) === 2, 'category hierarchy cache hit must still perform one fresh topology read');
hierarchyFenceCheck($db->calls[0]['use_cache'] === false && $db->calls[1]['use_cache'] === false, 'live category topology reads must bypass PrestaShop Db query cache');

$sql = $db->calls[1]['sql'];
hierarchyFenceCheck(str_contains($sql, 'leaf_shop'), 'live hierarchy must require target-shop leaf association');
hierarchyFenceCheck(str_contains($sql, 'parent_shop'), 'live hierarchy must require target-shop ancestor association');
hierarchyFenceCheck(str_contains($sql, 'leaf.nleft BETWEEN parent.nleft AND parent.nright'), 'live hierarchy must use current nested-set topology');

$db->rows = [];
try {
    $expand->invoke($sync, [10], 2);
    hierarchyFenceCheck(false, 'deleted or target-shop-unassociated mapped leaf must fail closed even after a prior cache hit');
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    hierarchyFenceCheck(
        str_contains($e->getMessage(), 'Mapped category is unavailable in target shop: 10'),
        'unexpected unavailable-category error: ' . $e->getMessage()
    );
}

$db->rows = false;
try {
    $expand->invoke($sync, [10], 2);
    hierarchyFenceCheck(false, 'failed live hierarchy SQL must fail closed');
} catch (Throwable $e) {
    hierarchyFenceCheck(
        str_contains($e->getMessage(), 'Could not inspect live target-shop category hierarchy'),
        'unexpected hierarchy SQL failure: ' . $e->getMessage()
    );
}

echo "Category hierarchy live fence: OK\n";
