<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Repository/ImageQueueRepository.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing ImageQueueRepository.php\n");
    exit(1);
}

$queue = (string) file_get_contents($path);
foreach ([
    ['private const ENQUEUE_CHUNK = 500;', 'image enqueue chunk bound'],
    ['private const MAX_URL_BYTES = 16384;', 'image URL persistence bound'],
    ['private const MAX_WRITE_VALUES_BYTES = 7340032;', 'escaped image SQL write budget'],
] as [$needle, $label]) {
    if (!str_contains($queue, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$enqueueStart = strpos($queue, 'public function enqueueBatch(int $runId, int $shopId, string $source, array $jobs): void');
$claimStart = strpos($queue, 'public function claim(string $worker, string $source', $enqueueStart === false ? 0 : $enqueueStart);
if ($enqueueStart === false || $claimStart === false || $enqueueStart >= $claimStart) {
    fwrite(STDERR, "FAIL: image enqueue batch method boundaries missing\n");
    exit(1);
}
$enqueue = substr($queue, $enqueueStart, $claimStart - $enqueueStart);
$ordered = [
    'strlen($url) > self::MAX_URL_BYTES',
    'pSQL($url, true)',
    '$valueBytes = strlen($value);',
    'if ($valueBytes > self::MAX_WRITE_VALUES_BYTES)',
    '$separatorBytes = $values === [] ? 0 : 1;',
    'count($values) >= self::ENQUEUE_CHUNK',
    '$valuesBytes + $separatorBytes + $valueBytes > self::MAX_WRITE_VALUES_BYTES',
    '$this->insertValues($values);',
    '$values[] = $value;',
];
$last = -1;
foreach ($ordered as $needle) {
    $pos = strpos($enqueue, $needle);
    if ($pos === false || $pos <= $last) {
        fwrite(STDERR, "FAIL: image queue escaped-byte/URL guard ordering regressed at {$needle}\n");
        exit(1);
    }
    $last = $pos;
}
if (!str_contains($enqueue, 'Escaped image queue row exceeds SQL write budget')) {
    fwrite(STDERR, "FAIL: oversized escaped image queue row must fail closed\n");
    exit(1);
}

$renewStart = strpos($queue, 'public function renew(int $id, string $token): bool');
$lockStart = strpos($queue, 'public function lockOwned(int $id, string $token): array');
if ($renewStart === false || $lockStart === false || $renewStart >= $lockStart) {
    fwrite(STDERR, "FAIL: image queue renew method boundaries missing\n");
    exit(1);
}

$renew = substr($queue, $renewStart, $lockStart - $renewStart);
if (!str_contains($renew, "WHERE status='processing' AND locked_by='%s' AND locked_until>NOW()")) {
    fwrite(STDERR, "FAIL: image heartbeat must renew every still-active row owned by the claim token\n");
    exit(1);
}
if (str_contains($renew, 'WHERE id_queue=%d')) {
    fwrite(STDERR, "FAIL: image heartbeat must not renew only the current queue row\n");
    exit(1);
}
if (!str_contains($renew, 'return $this->ownsActiveLease($id, $token);')) {
    fwrite(STDERR, "FAIL: image batch heartbeat must still verify current-row ownership\n");
    exit(1);
}
if (!str_contains($renew, 'locked_until=DATE_ADD(NOW(),INTERVAL %d MINUTE)')) {
    fwrite(STDERR, "FAIL: image batch heartbeat no longer extends the lease\n");
    exit(1);
}
if (!str_contains($renew, 'locked_until>NOW()')) {
    fwrite(STDERR, "FAIL: expired image leases must not be reclaimed by heartbeat\n");
    exit(1);
}

echo "Image queue bounded write/lease contract: OK\n";
