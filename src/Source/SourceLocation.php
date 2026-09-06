<?php
namespace Lp\MatterhornImport\Source;

final class SourceLocation
{
    private const MAX_LENGTH = 4096;

    public function validate(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new \InvalidArgumentException('Source XML location is required.');
        }
        if (strlen($location) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Source XML location is too long.');
        }

        if ($this->isRemote($location)) {
            $parts = parse_url($location);
            if (!is_array($parts) || empty($parts['host'])) {
                throw new \InvalidArgumentException('Source XML URL is invalid.');
            }
            if (isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Credentials are not allowed in the Source XML URL.');
            }
            return $location;
        }

        if (!is_file($location) || !is_readable($location)) {
            throw new \InvalidArgumentException('Source XML local file is not readable.');
        }

        return $location;
    }

    public function isRemote(string $location): bool
    {
        $scheme = strtolower((string) parse_url($location, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }
}
