<?php
namespace Lp\MatterhornImport\Util;

final class TransientDatabaseFailure
{
    private const CODES = ['1205','1213','40001'];
    private const MESSAGES = ['deadlock found','lock wait timeout exceeded','sqlstate[40001]','serialization failure','try restarting transaction','server has gone away','lost connection'];

    public static function isRetryable(\Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (in_array((string) $current->getCode(), self::CODES, true)) { return true; }
            $message = strtolower($current->getMessage());
            foreach (self::MESSAGES as $needle) { if (str_contains($message, $needle)) { return true; } }
        }
        return false;
    }
}
