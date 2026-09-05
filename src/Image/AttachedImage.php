<?php
namespace Lp\MatterhornImport\Image;

final class AttachedImage
{
    public function __construct(
        public int $idImage,
        public string $basePath
    ) {
    }
}
