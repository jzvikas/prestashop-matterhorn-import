<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Lp\MatterhornImport\Image\ImageFailureClassifier;

function classifierCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$classifier = new ImageFailureClassifier();

classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image exceeds PrestaShop resize memory limit')) === false,
    'resize memory-limit rejection must be permanent on the same worker/server'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image exceeds maximum download size')) === false,
    'download-size rejection must remain permanent'
);
classifierCheck(
    $classifier->isRetryable(new InvalidArgumentException('Only HTTP(S) image URLs allowed')) === false,
    'invalid image URL arguments must remain permanent'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image URL credentials are not allowed')) === false,
    'credential-bearing image URL rejection must be permanent'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image HTTP failure 404 Not Found')) === false,
    'HTTP 404 must remain permanent'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image HTTP failure 429 Too Many Requests')) === true,
    'HTTP 429 must remain retryable'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image HTTP failure 503 Service Unavailable')) === true,
    'HTTP 503 must remain retryable'
);
classifierCheck(
    $classifier->isRetryable(new RuntimeException('Image HTTP failure 0 operation timed out')) === true,
    'transport timeout without a classified HTTP status must remain retryable'
);

echo "Image failure classifier: OK\n";
