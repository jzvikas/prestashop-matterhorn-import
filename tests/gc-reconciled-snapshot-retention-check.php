<?php

declare(strict_types=1);

$path = __DIR__ . '/../src/Gc/GcService.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Could not read GcService.php\n");
    exit(1);
}

$required = [
    "newer.id_shop=r.id_shop AND newer.source=r.source AND newer.id_run>r.id_run",
    "newer.status='completed'",
    "newer.read_status='completed'",
    "newer.import_status='completed'",
    "newer.update_status='completed'",
    "newer.remove_status='completed'",
    "newer.image_reconcile_status='completed'",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Snapshot GC is missing the reconciled-generation retention fence: {$needle}\n");
        exit(1);
    }
}

$unsafe = "newer.id_shop=r.id_shop AND newer.source=r.source AND newer.id_run>r.id_run)'";
if (str_contains($source, $unsafe)) {
    fwrite(STDERR, "Snapshot GC still allows any newer run to make the retained image manifest collectible\n");
    exit(1);
}

echo "GC reconciled snapshot retention contract: OK\n";
