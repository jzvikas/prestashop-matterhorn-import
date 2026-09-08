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

        // Authorization headers commonly contain a scheme and a credential separated
        // by whitespace ("Bearer token" / "Basic base64"). Redact the full value,
        // not only the scheme token.
        $message = preg_replace(
            '/\bauthorization\b\s*[:=]\s*(?:(?:Bearer|Basic)\s+)?[^\s,;]+/i',
            'authorization=***',
            $message
        ) ?? $message;

        // Redact common single-token header/assignment forms.
        $message = preg_replace(
            '/\b(AccessKey|password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret)\b\s*[:=]\s*[^\s,;]+/i',
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
