<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\MatterhornXmlSource;

$fixture = __DIR__ . '/fixtures/matterhorn-sample.xml';
$source = new MatterhornXmlSource($fixture);
$first = [];
foreach ($source->rows() as $row) {
    $first[] = (string) ($row['id'] ?? '');
    break;
}
$checkpoint = $source->byteCheckpoint();
if ($first !== ['206161'] || $checkpoint <= 0) {
    throw new RuntimeException('Prewk stream did not expose a durable first-product byte cursor');
}

$resumed = new MatterhornXmlSource($fixture);
$ids = [];
foreach ($resumed->rowsFromByte($checkpoint, 1) as $row) {
    $ids[] = (string) ($row['id'] ?? '');
}
if ($ids !== ['34375', '228723']) {
    throw new RuntimeException('Prewk byte resume repeated or skipped Matterhorn products');
}
if ($resumed->byteCheckpoint() <= $checkpoint) {
    throw new RuntimeException('Prewk byte cursor did not advance after resume');
}

// Recovery compatibility: record-based resume still works if the tiny cursor
// sidecar disappears, but this is not the normal AJAX path.
$recovery = new MatterhornXmlSource($fixture);
$recoveryIds = [];
foreach ($recovery->rowsFrom(1) as $row) {
    $recoveryIds[] = (string) ($row['id'] ?? '');
    break;
}
if ($recoveryIds !== ['34375'] || $recovery->byteCheckpoint() <= $checkpoint) {
    throw new RuntimeException('Record recovery path could not rebuild a Prewk byte cursor');
}

echo "Matterhorn Prewk byte resume: OK\n";
