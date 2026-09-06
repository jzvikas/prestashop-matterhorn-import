<?php
namespace Lp\MatterhornImport\Admin;

final class AdminErrorReporter
{
    public function report(string $operation, \Throwable $exception): string
    {
        try {
            $reference = strtoupper(bin2hex(random_bytes(6)));
        } catch (\Throwable) {
            $reference = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
        }

        try {
            \PrestaShopLogger::addLog(
                sprintf(
                    '[MatterhornImport][%s][%s] %s',
                    preg_replace('/[^A-Za-z0-9_.-]+/', '-', $operation) ?: 'operation',
                    $reference,
                    $this->safeMessage($exception)
                ),
                3
            );
        } catch (\Throwable) {
            // Error reporting must never replace the handled AJAX response with HTML 500.
        }

        return $reference;
    }

    public function safeMessage(\Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?? trim($exception->getMessage());
        $message = preg_replace('#(https?://)([^/@\s:]+):([^/@\s]+)@#i', '$1***:***@', $message) ?? $message;
        $message = preg_replace('/(AccessKey|password|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=***', $message) ?? $message;

        return mb_substr($message, 0, 1200, 'UTF-8');
    }
}
