<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\Util\ShopContextManager;

final class PrestaImageProcessor
{
    public function __construct(private ShopContextManager $shopContext) {}

    public function attach(int $productId, int $shopId, DownloadedImage $download, int $position, bool $cover): AttachedImage
    {
        $this->shopContext->activate($shopId);
        $image = new \Image();
        $image->id_product = $productId;
        $image->position = $position + 1;
        $image->cover = null;
        if (!$image->add()) { throw new \RuntimeException('Cannot create PrestaShop image row'); }
        $base = null;
        try {
            if (!$image->associateTo([$shopId], $productId)) { throw new \RuntimeException('Cannot associate image to target shop'); }
            $base = $image->getPathForCreation();
            if (!\ImageManager::resize($download->path, $base . '.jpg')) { throw new \RuntimeException('Cannot create master image'); }
            foreach (\ImageType::getImagesTypes('products') as $type) {
                $name = stripslashes((string) $type['name']);
                if (!\ImageManager::resize($download->path, $base . '-' . $name . '.jpg', (int) $type['width'], (int) $type['height'])) {
                    throw new \RuntimeException('Thumbnail failed: ' . $name);
                }
            }
            if ($cover && !\Image::getCover($productId, $shopId)) {
                $image->cover = true;
                if (!$image->update()) { throw new \RuntimeException('Cannot set product cover image'); }
            }
            return new AttachedImage((int) $image->id, $base);
        } catch (\Throwable $e) {
            try { $image->delete(); } catch (\Throwable) {}
            throw $e;
        }
    }
}
