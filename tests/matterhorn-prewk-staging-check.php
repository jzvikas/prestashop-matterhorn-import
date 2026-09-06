<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$composer = (string) file_get_contents($root . '/composer.json');
$source = (string) file_get_contents($root . '/src/Source/MatterhornXmlSource.php');
$configured = (string) file_get_contents($root . '/src/Source/ConfiguredMatterhornXmlSource.php');
$read = (string) file_get_contents($root . '/src/Import/ReadStage.php');
$runSource = (string) file_get_contents($root . '/src/Source/RunSourceSnapshotManager.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!class_exists(Prewk\XmlStringStreamer::class)) {
    $fail('prewk/xml-string-streamer dependency is not installed');
}
if (!str_contains($composer, 'prewk/xml-string-streamer')) {
    $fail('composer runtime does not require prewk/xml-string-streamer');
}
if (!str_contains($source, 'new UniqueNode([') || !str_contains($source, "'uniqueNode' => 'product'")) {
    $fail('Matterhorn XML source is not using Prewk UniqueNode for product streaming');
}
if (!str_contains($source, 'new XmlStringStreamer($parser, $stream)')) {
    $fail('Matterhorn XML source is not driven by the Prewk streamer');
}
if (!str_contains($source, 'simplexml_load_string')) {
    $fail('Matterhorn product fragment is not parsed independently with SimpleXML');
}
if (str_contains($source, 'new \\XMLReader()')) {
    $fail('legacy XMLReader product scanner is still active');
}
if (is_file($root . '/src/Source/MatterhornByteStreamSource.php')) {
    $fail('custom Matterhorn byte XML parser must be removed');
}
if (!str_contains($source, '$parser->getCurrentWorkingBlob()')) {
    $fail('Prewk unread buffer is not used to derive an exact safe resume cursor');
}
if (!str_contains($source, '$nextByte = $byteOffset + $readBytes - strlen($workingBlob)')) {
    $fail('Prewk byte cursor calculation is missing');
}
if (!str_contains($configured, "rowsFromByte(\$checkpoint['byte'], \$offset)")) {
    $fail('normal frozen-source resume does not seek directly to the Prewk byte cursor');
}
if (!str_contains($runSource, 'read.checkpoint.json') || !str_contains($runSource, 'persistCheckpoint')) {
    $fail('crash-safe Prewk cursor sidecar is missing');
}
if (!str_contains($read, 'WRITE_BATCH = 250')) {
    $fail('READ shared-hosting DB commit batch must be 250');
}
if (!str_contains($read, '$this->budget->shouldStop()')) {
    $fail('READ must remain AJAX/time-budget bounded on shared hosting');
}
if (!str_contains($read, 'persistRunCheckpointBestEffort')) {
    $fail('READ does not persist the Prewk cursor after DB commits');
}
if (!str_contains($read, 'Write only after the DB transaction committed')) {
    $fail('Prewk cursor must remain ordered after the authoritative DB commit');
}

echo "Matterhorn prewk staging architecture: OK\n";
