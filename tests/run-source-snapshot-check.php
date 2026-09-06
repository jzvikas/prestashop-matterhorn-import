<?php
declare(strict_types=1);

$base = sys_get_temp_dir() . '/matterhorn-run-source-' . bin2hex(random_bytes(5));
define('_PS_MODULE_DIR_', $base . '/modules/');
mkdir(_PS_MODULE_DIR_ . 'matterhornimport/var', 0750, true);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\RunSourceSnapshotManager;

$input = $base . '/supplier.xml';
file_put_contents($input, "<products><product id=\"1\"><name>A</name></product></products>\n");
$fingerprint = hash('sha256', 'fixture');
$manager = new RunSourceSnapshotManager();

try {
    $snapshot = $manager->create(7, 3, $input, $fingerprint, false);
    if (!is_file($snapshot['path']) || $snapshot['fingerprint'] !== $fingerprint) {
        throw new RuntimeException('Run source snapshot was not created');
    }

    $frozen = (string) file_get_contents($snapshot['path']);
    file_put_contents($input, "<products><product id=\"2\"><name>B</name></product></products>\n");
    if ((string) file_get_contents($snapshot['path']) !== $frozen) {
        throw new RuntimeException('Run source snapshot changed with mutable input');
    }

    $loaded = $manager->load(7, 3);
    if ($loaded === null || $loaded['fingerprint'] !== $fingerprint || $loaded['bytes'] !== strlen($frozen)) {
        throw new RuntimeException('Frozen run source could not be reloaded');
    }

    $manager->release(7, 3);
    if ($manager->load(7, 3) !== null) {
        throw new RuntimeException('Frozen run source was not released');
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($base);
}

echo "Frozen Matterhorn run source snapshot: OK\n";
