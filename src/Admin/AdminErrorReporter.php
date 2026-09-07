<?php
namespace Lp\MatterhornImport\Admin;

use Lp\MatterhornImport\Util\DiagnosticMessageSanitizer;

final class AdminErrorReporter
{
    public function __construct(private DiagnosticMessageSanitizer $sanitizer)
    {
    }

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
                    $this->sanitizer->sanitize($exception, 1200)
                ),
                3
            );
        } catch (\Throwable) {
            // Error reporting must never replace the handled AJAX response with HTML 500.
        }

        return $reference;
    }

    /**
     * Exception text is deliberately not returned to AJAX callers.
     *
     * Runtime exception messages can contain SQL, local filesystem paths, remote
     * URLs, hostnames and other implementation details. The correlation reference
     * returned by report() is the only diagnostic identifier exposed to the BO.
     */
    public function safeMessage(\Throwable $exception): string
    {
        unset($exception);

        return '';
    }
}
