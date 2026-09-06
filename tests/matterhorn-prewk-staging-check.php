<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$composer = (string) file_get_contents($root . '/composer.json');
$source = (string) file_get_contents($root . '/src/Source/MatterhornXmlSource.php');
$configured = (string) file_get_contents($root . '/src/Source/ConfiguredMatterhornXmlSource.php');
$read = (string) file_get_contents($root . '/src/Import/ReadStage.php');
$runner = (string) file_get_contents($root . '/src/Import/ImportRunner.php');
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
if (!str_contains($source, 'XmlStringStreamer::createUniqueNodeParser')) {
    $fail('Matterhorn XML source is not using the prewk unique-node parser');
}
if (!str_contains($source, "'uniqueNode' => 'product'")) {
    $fail('Matterhorn XML source is not configured for product nodes');
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
if (is_file($root . '/src/Contract/ByteCheckpointableSourceInterface.php')) {
    $fail('obsolete byte checkpoint interface must be removed');
}
if (!str_contains($configured, 'new MatterhornXmlSource($snapshot[\'path\'])')) {
    $fail('frozen run source is not delegated to the prewk Matterhorn source');
}
if (str_contains($runSource, 'read.checkpoint.json') || str_contains($runSource, 'persistCheckpoint')) {
    $fail('byte checkpoint sidecar must not remain in the run source manager');
}
if (!str_contains($read, 'WRITE_BATCH = 250')) {
    $fail('READ shared-hosting DB commit batch must be 250');
}
if (!str_contains($read, 'foreach ($this->source->rows() as $row)')) {
    $fail('READ must consume one linear supplier stream');
}
if (str_contains($read, 'rowsFrom($checkpoint)') || str_contains($read, 'shouldStop()')) {
    $fail('READ must not be sliced into repeated XML checkpoint rescans');
}
if (!str_contains($runner, '$this->read->run($runId, 0, 0)')) {
    $fail('runner must execute XML staging as one complete action');
}
if (!str_contains($runner, "pauseBetweenStages(\$runId, 'import')")) {
    $fail('bounded AJAX flow must return after XML staging before DB import stage');
}

echo "Matterhorn prewk staging architecture: OK\n";
