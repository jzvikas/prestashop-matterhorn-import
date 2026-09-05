<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$queue = (string) file_get_contents($root . '/src/Repository/ImageQueueRepository.php');
$import = (string) file_get_contents($root . '/src/Import/ImportStage.php');
$update = (string) file_get_contents($root . '/src/Import/UpdateStage.php');
$newProduct = (string) file_get_contents($root . '/src/NewProduct/NewProductWorker.php');
$revalidation = (string) file_get_contents($root . '/src/Image/ImageRevalidationScheduler.php');

foreach ([$queue, $import, $update, $newProduct, $revalidation] as $source) {
    if ($source === '') {
        fwrite(STDERR, "FAIL: authoritative image manifest fence source missing\n");
        exit(1);
    }
}

$method = 'supersedeOlderUnresolvedForAuthoritativeManifest';
if (!str_contains($queue, 'public function ' . $method . '(')) {
    fwrite(STDERR, "FAIL: image queue must expose authoritative manifest supersede primitive\n");
    exit(1);
}

foreach ([
    "SET status='done',locked_by=NULL,locked_until=NULL,available_at=NULL",
    "id_shop=%d AND source='%s' AND source_key='%s' AND id_product=%d AND id_run<%d",
    "status IN ('pending','processing','failed')",
    'removed from newer authoritative image manifest',
    'return (int) $db->Affected_Rows();',
] as $needle) {
    if (!str_contains($queue, $needle)) {
        fwrite(STDERR, "FAIL: authoritative manifest queue fence missing {$needle}\n");
        exit(1);
    }
}

if (str_contains($queue, 'NOT IN (')) {
    fwrite(STDERR, "FAIL: authoritative manifest supersede must not build an unbounded URL/hash NOT IN list\n");
    exit(1);
}

$assertOrdered = static function (string $source, string $enqueueNeedle, string $label) use ($method): void {
    $enqueue = strpos($source, $enqueueNeedle);
    $supersede = strpos($source, '$this->images->' . $method, $enqueue === false ? 0 : $enqueue);
    if ($enqueue === false || $supersede === false || $enqueue >= $supersede) {
        fwrite(STDERR, "FAIL: {$label} must enqueue the complete desired manifest before superseding older unresolved rows\n");
        exit(1);
    }
};

$assertOrdered($import, '$this->images->enqueue(', 'IMPORT');
$assertOrdered($update, '$this->images->enqueue(', 'UPDATE');
$assertOrdered($newProduct, '$this->images->enqueue(', 'new-product worker');

$imagesChanged = strpos($update, 'if (!$imagesSame) {');
$enqueue = strpos($update, '$this->images->enqueue(', $imagesChanged === false ? 0 : $imagesChanged);
$supersede = strpos($update, '$this->images->' . $method, $enqueue === false ? 0 : $enqueue);
$mappingSave = strpos($update, '$this->mapping->save(', $supersede === false ? 0 : $supersede);
if ($imagesChanged === false || $enqueue === false || $supersede === false || $mappingSave === false || !($imagesChanged < $enqueue && $enqueue < $supersede && $supersede < $mappingSave)) {
    fwrite(STDERR, "FAIL: UPDATE manifest supersede must stay inside the image-change branch before mapping durability\n");
    exit(1);
}

if (str_contains($revalidation, $method)) {
    fwrite(STDERR, "FAIL: partial age-based image revalidation must never supersede other authoritative manifest URLs\n");
    exit(1);
}

if (!str_contains($revalidation, '$this->queue->enqueueBatch(')) {
    fwrite(STDERR, "FAIL: image revalidation must remain a partial enqueue path\n");
    exit(1);
}

echo "Authoritative image manifest queue fence contract: OK\n";
