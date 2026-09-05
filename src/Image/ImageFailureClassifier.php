<?php
namespace Lp\MatterhornImport\Image;

final class ImageFailureClassifier
{
    public function isRetryable(\Throwable $error): bool
    {
        if ($error instanceof \InvalidArgumentException) {
            return false;
        }
        $message = strtolower($error->getMessage());
        foreach ([
            'private/reserved or unresolved image host blocked',
            'image connection endpoint changed after validation',
            'image exceeds maximum download size',
            'unsupported image mime',
            'invalid or oversized image dimensions',
            'invalid image url port',
            'credentials in image urls are not allowed',
            'only http(s) image urls allowed',
        ] as $permanent) {
            if (str_contains($message, $permanent)) {
                return false;
            }
        }
        if (preg_match('/image http failure\s+(\d{3})\b/', $message, $match) === 1) {
            $status = (int) $match[1];
            if (in_array($status, [408, 425, 429], true) || $status >= 500) {
                return true;
            }
            if ($status >= 400 && $status < 500) {
                return false;
            }
        }
        return true;
    }
}
