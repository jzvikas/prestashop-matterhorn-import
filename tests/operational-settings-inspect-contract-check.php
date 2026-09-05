<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Config/OperationalSettings.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing OperationalSettings.php\n");
    exit(1);
}

$text = (string) file_get_contents($path);
$start = strpos($text, 'public function inspect(int $shopId): array');
$end = strpos($text, 'public function validate(', $start === false ? 0 : $start);
if ($start === false || $end === false || $start >= $end) {
    fwrite(STDERR, "FAIL: OperationalSettings::inspect boundaries missing\n");
    exit(1);
}

$inspect = substr($text, $start, $end - $start);
$checks = [
    ['$groupId = $this->shopGroupId($shopId);', 'inspect must resolve the requested shop group'],
    ['Configuration::get($key, null, $groupId, $shopId)', 'inspect must read exact shop/group configuration'],
    ['FILTER_VALIDATE_INT', 'inspect must validate stored integers'],
    ["['options'=>['min_range'=>\$min,'max_range'=>\$max]]", 'inspect must apply declared integer bounds'],
    ['$valid = $missing || $parsed !== false;', 'inspect must distinguish invalid stored values'],
    ["'effective'=>\$valid?(int)\$parsed:\$default", 'invalid stored values must use safe default'],
    ["'uses_default'=>\$missing||!\$valid", 'inspect must expose default fallback'],
    ["'min'=>\$min", 'inspect must expose minimum bound'],
    ["'max'=>\$max", 'inspect must expose maximum bound'],
];
foreach ($checks as [$needle, $label]) {
    if (!str_contains($inspect, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Operational settings inspect contract: OK\n";
