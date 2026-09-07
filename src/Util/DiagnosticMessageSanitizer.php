<?php
namespace Lp\MatterhornImport\Util;

final class DiagnosticMessageSanitizer
{
    public function sanitize(\Throwable|string $error, int $limit = 1200): string
    {
        $message = $error instanceof \Throwable
            ? get_class($error) . ': ' . $error->getMessage()
            : (string) $error;

        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        // URL user-info must never reach persistent diagnostics or server logs.
        $message = preg_replace(
            '#(https?://)([^/@\s:]+):([^/@\s]+)@#i',
            '$1***:***@',
            $message
        ) ?? $message;

        // Redact common header/assignment forms, including bearer/basic authorization values.
        $message = preg_replace(
            '/\b(AccessKey|password|passwd|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=***',
            $message
        ) ?? $message;

        // Redact URI query parameters even when they appear in the middle of a URL.
        $message = preg_replace(
            '/([?&](?:password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret)=)[^&\s]*/i',
            '$1***',
            $message
        ) ?? $message;

        return mb_substr($message, 0, max(0, $limit), 'UTF-8');
    }
}
