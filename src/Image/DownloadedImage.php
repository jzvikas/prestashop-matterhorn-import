<?php
namespace Lp\MatterhornImport\Image;

final readonly class DownloadedImage
{
    public function __construct(
        public string $path,
        public string $mime,
        public int $width,
        public int $height,
        public int $bytes,
        public string $contentHash,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }
}
