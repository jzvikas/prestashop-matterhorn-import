<?php
namespace Lp\MatterhornImport\Image;

use Lp\MatterhornImport\Util\ShopContextManager;

final class PrestaImageProcessor
{
    public function __construct(private ShopContextManager $shopContext)
    {
    }

    public function attach(int $productId, int $shopId, DownloadedImage $download, int $position, bool $cover): AttachedImage
    {
        $this->shopContext->activate($shopId);
        $image = new \Image();
        $image->id_product = $productId;
        $image->position = $position + 1;
        $image->cover = null;
        $base = null;
        if (!$image->add()) {
            throw new \RuntimeException('Cannot create PrestaShop image row');
        }
        try {
            if (!$image->associateTo([$shopId], $productId)) {
                throw new \RuntimeException('Cannot associate image to target shop');
            }
            $base = $image->getPathForCreation();
            if (!\ImageManager::resize($download->path, $base . '.jpg')) {
                throw new \RuntimeException('Cannot create master image');
            }
            foreach (\ImageType::getImagesTypes('products') as $type) {
                $name = stripslashes((string) $type['name']);
                if (!\ImageManager::resize($download->path, $base . '-' . $name . '.jpg', (int) $type['width'], (int) $type['height'])) {
                    throw new \RuntimeException('Thumbnail failed: ' . $name);
                }
            }
            if ($cover && !\Image::getCover($productId, $shopId)) {
                $image->cover = true;
                if (!$image->update()) {
                    throw new \RuntimeException('Cannot set product cover image');
                }
            }
            return new AttachedImage((int) $image->id, $base);
        } catch (\Throwable $e) {
            try { $image->delete(); } catch (\Throwable) {}
            if ($base !== null) {
                $this->cleanupFilesystem(new AttachedImage((int) $image->id, $base));
            }
            throw $e;
        }
    }

    public function transferCover(int $oldImageId, int $newImageId, int $productId, int $shopId): void
    {
        if ($oldImageId <= 0 || $newImageId <= 0 || $productId <= 0 || $shopId <= 0 || $oldImageId === $newImageId) {
            return;
        }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $valid = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage` WHERE id_product=%d AND id_image IN (%d,%d)', _DB_PREFIX_, $productId, $oldImageId, $newImageId));
        if ($valid !== 2) {
            throw new \RuntimeException('Cannot transfer cover between invalid product images');
        }
        if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=NULL WHERE id_product=%d AND id_shop=%d AND cover=1', _DB_PREFIX_, $productId, $shopId))) {
            throw new \RuntimeException('Cannot clear previous target-shop cover');
        }
        if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=1 WHERE id_image=%d AND id_product=%d AND id_shop=%d', _DB_PREFIX_, $newImageId, $productId, $shopId))) {
            throw new \RuntimeException('Cannot set replacement target-shop cover');
        }
        $oldShopCount = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d', _DB_PREFIX_, $oldImageId));
        if ($oldShopCount === 1) {
            if (!$db->execute(sprintf('UPDATE `%simage` SET cover=NULL WHERE id_product=%d AND cover=1', _DB_PREFIX_, $productId))) {
                throw new \RuntimeException('Cannot clear previous global cover');
            }
            if (!$db->execute(sprintf('UPDATE `%simage` SET cover=1 WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $newImageId, $productId))) {
                throw new \RuntimeException('Cannot set replacement global cover');
            }
        }
    }

    public function deleteImage(int $idImage, int $productId, int $shopId): bool
    {
        if ($idImage <= 0 || $productId <= 0 || $shopId <= 0) {
            return false;
        }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $image = new \Image($idImage);
        if (!\Validate::isLoadedObject($image) || (int) $image->id_product !== $productId) {
            return false;
        }
        $shopRows = $db->executeS(sprintf('SELECT id_shop FROM `%simage_shop` WHERE id_image=%d AND id_product=%d ORDER BY id_shop', _DB_PREFIX_, $idImage, $productId)) ?: [];
        if (count($shopRows) !== 1 || (int) $shopRows[0]['id_shop'] !== $shopId) {
            return false;
        }
        if (!$image->delete()) {
            throw new \RuntimeException('Cannot delete image ' . $idImage);
        }
        return true;
    }

    public function cleanupFilesystem(AttachedImage $attached): void
    {
        $root = realpath(_PS_PROD_IMG_DIR_);
        $directory = realpath(dirname($attached->basePath));
        if ($root === false || $directory === false) {
            return;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $extension) {
            $master = $attached->basePath . '.' . $extension;
            if (is_file($master)) {
                @unlink($master);
            }
            foreach (glob($attached->basePath . '-*.' . $extension) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
