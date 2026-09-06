<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Source\SourceLocation;

$source = new SourceLocation();
$url = 'http://srv0.matterhorn-wholesale.com/xmldata/products_full.php?type=products_xml';

if ($source->validate($url) !== $url) {
    fwrite(STDERR, "FAIL: valid Matterhorn HTTP feed URL must be accepted unchanged\n");
    exit(1);
}
if (!$source->isRemote($url)) {
    fwrite(STDERR, "FAIL: Matterhorn HTTP feed URL must be classified as remote\n");
    exit(1);
}
if ($source->validate('  ' . $url . '  ') !== $url) {
    fwrite(STDERR, "FAIL: source URL validation must trim surrounding whitespace\n");
    exit(1);
}

try {
    $source->validate('/definitely/missing/matterhorn/source.xml');
    fwrite(STDERR, "FAIL: unreadable local source path must be rejected\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    if (!str_contains($e->getMessage(), 'local file is not readable')) {
        fwrite(STDERR, "FAIL: local source rejection must stay distinct from remote URL validation\n");
        exit(1);
    }
}

echo "Source location contract: OK\n";
