<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/src/Repository/ImageQueueRepository.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing ImageQueueRepository.php\n");
    exit(1);
}

$queue = (string) file_get_contents($path);
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

echo "Image queue batch lease contract: OK\n";
