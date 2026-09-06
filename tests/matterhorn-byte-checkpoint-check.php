<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\MatterhornByteStreamSource;

$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$source = new MatterhornByteStreamSource($fixture);
$ids = [];
$firstCheckpoint = 0;
foreach ($source->rows() as $row) {
    $ids[] = (string) ($row['id'] ?? '');
    if (count($ids) === 1) {
        $firstCheckpoint = $source->byteCheckpoint();
        break;
    }
}
if ($ids !== ['206161'] || $firstCheckpoint <= 0) {
    throw new RuntimeException('Byte stream did not expose the first durable product checkpoint');
}

$resumed = new MatterhornByteStreamSource($fixture);
$resumedIds = [];
foreach ($resumed->rowsFromByte($firstCheckpoint, 1) as $row) {
    $resumedIds[] = (string) ($row['id'] ?? '');
}
if ($resumedIds !== ['34375', '228723']) {
    throw new RuntimeException('Byte checkpoint resume repeated/skipped Matterhorn products');
}
if ($resumed->byteCheckpoint() <= $firstCheckpoint) {
    throw new RuntimeException('Byte checkpoint did not advance after resumed READ');
}

$legacy = new MatterhornByteStreamSource($fixture);
$legacyIds = [];
foreach ($legacy->rowsFrom(1) as $row) {
    $legacyIds[] = (string) ($row['id'] ?? '');
    break;
}
if ($legacyIds !== ['34375'] || $legacy->byteCheckpoint() <= $firstCheckpoint) {
    throw new RuntimeException('Legacy record checkpoint did not migrate to a byte checkpoint');
}

$truncated = tempnam(sys_get_temp_dir(), 'matterhorn-truncated-');
if ($truncated === false) {
    throw new RuntimeException('Could not create truncated Matterhorn fixture');
}
try {
    $xml = (string) file_get_contents($fixture);
    $cut = strpos($xml, '<product id="228723">');
    if ($cut === false) {
        throw new RuntimeException('Could not locate truncation fixture product');
    }
    $partial = substr($xml, 0, $cut + 120);
    file_put_contents($truncated, $partial);

    $thrown = false;
    try {
        foreach ((new MatterhornByteStreamSource($truncated))->rows() as $_row) {
        }
    } catch (RuntimeException $exception) {
        $thrown = str_contains($exception->getMessage(), 'Unexpected EOF inside Matterhorn <product>')
            || str_contains($exception->getMessage(), 'unexpected EOF before </products>');
    }
    if (!$thrown) {
        throw new RuntimeException('Byte stream must fail closed on a truncated READ feed');
    }
} finally {
    @unlink($truncated);
}

echo "Matterhorn byte checkpoint resume: OK\n";
