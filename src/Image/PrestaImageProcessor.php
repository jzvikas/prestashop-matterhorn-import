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
        if (!\ImageManager::checkImageMemoryLimit($download->path)) {
            throw new \RuntimeException('Image exceeds PrestaShop resize memory limit');
        }
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
        $valid = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage` WHERE id_product=%d AND id_image IN (%d,%d)', _DB_PREFIX_, $productId, $oldImageId, $newImageId), false);
        if ($valid !== 2) {
            throw new \RuntimeException('Cannot transfer cover between invalid product images');
        }

        $this->setTargetShopCover($db, $productId, $shopId, $newImageId);

        // image.cover is PrestaShop's legacy/global shadow while image_shop.cover is the
        // authoritative shop-scoped cover. Move that shadow only when the old image is
        // itself the current global cover and is still exclusive to this target shop in
        // the same SQL statement. A concurrent/foreign shop association therefore makes
        // this update a safe no-op instead of letting stale pre-read topology overwrite it.
        $imageTable = _DB_PREFIX_ . 'image';
        $imageShopTable = _DB_PREFIX_ . 'image_shop';
        $globalSql = sprintf(
            'UPDATE `%1$s` i ' .
            'INNER JOIN `%1$s` old_cover ON old_cover.id_image=%2$d AND old_cover.id_product=i.id_product AND old_cover.cover=1 ' .
            'INNER JOIN `%1$s` replacement_image ON replacement_image.id_image=%3$d AND replacement_image.id_product=i.id_product ' .
            'INNER JOIN `%4$s` old_target ON old_target.id_image=old_cover.id_image AND old_target.id_product=i.id_product AND old_target.id_shop=%5$d ' .
            'INNER JOIN `%4$s` new_target ON new_target.id_image=replacement_image.id_image AND new_target.id_product=i.id_product AND new_target.id_shop=%5$d ' .
            'LEFT JOIN `%4$s` old_other ON old_other.id_image=old_cover.id_image AND old_other.id_product=i.id_product AND old_other.id_shop<>%5$d ' .
            'SET i.cover=CASE WHEN i.id_image=%3$d THEN 1 ELSE NULL END ' .
            'WHERE i.id_product=%6$d AND i.id_image IN (%2$d,%3$d) AND old_other.id_image IS NULL',
            $imageTable,
            $oldImageId,
            $newImageId,
            $imageShopTable,
            $shopId,
            $productId
        );
        if (!$db->execute($globalSql)) {
            throw new \RuntimeException('Cannot transfer exclusive global image cover shadow');
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

        // Global Image::delete() is destructive. Always inspect live shop ownership instead of
        // PrestaShop's query cache; another worker/reconcile pass may have changed image_shop.
        $shopRows = $db->executeS(sprintf(
            'SELECT id_shop FROM `%simage_shop` WHERE id_image=%d AND id_product=%d ORDER BY id_shop',
            _DB_PREFIX_, $idImage, $productId
        ), true, false) ?: [];
        if (count($shopRows) !== 1 || (int) $shopRows[0]['id_shop'] !== $shopId) {
            return false;
        }

        // Revalidate immediately before the destructive ObjectModel call. This narrows the
        // select-to-delete race for GC paths that can run outside the import transaction.
        $exclusive = (int) $db->getValue(sprintf(
            'SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d AND id_product=%d AND id_shop=%d',
            _DB_PREFIX_, $idImage, $productId, $shopId
        ), false);
        $allShops = (int) $db->getValue(sprintf(
            'SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d AND id_product=%d',
            _DB_PREFIX_, $idImage, $productId
        ), false);
        if ($exclusive !== 1 || $allShops !== 1) {
            return false;
        }

        if (!$image->delete()) {
            throw new \RuntimeException('Cannot delete image ' . $idImage);
        }
        return true;
    }

    /** @param list<array{id_image:int,position:int,is_cover:bool}> $placements */
    public function syncProductPlacement(int $productId, int $shopId, array $placements): void
    {
        if ($productId <= 0 || $shopId <= 0) {
            throw new \InvalidArgumentException('Invalid image placement context');
        }
        if ($placements === []) {
            // Preserve any manual BO cover when Matterhorn has no desired images.
            return;
        }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $byImage = [];
        foreach ($placements as $placement) {
            $idImage = (int) ($placement['id_image'] ?? 0);
            if ($idImage <= 0) {
                continue;
            }
            $position = max(0, (int) ($placement['position'] ?? 0));
            if (!isset($byImage[$idImage]) || $position < $byImage[$idImage]['position']) {
                $byImage[$idImage] = [
                    'id_image' => $idImage,
                    'position' => $position,
                    'is_cover' => (bool) ($placement['is_cover'] ?? false),
                ];
            } elseif ((bool) ($placement['is_cover'] ?? false)) {
                $byImage[$idImage]['is_cover'] = true;
            }
        }
        if ($byImage === []) {
            return;
        }
        uasort($byImage, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach ($byImage as $idImage => $placement) {
            $valid = (bool) $db->getValue(sprintf(
                'SELECT 1 FROM `%simage` i INNER JOIN `%simage_shop` ish ON ish.id_image=i.id_image AND ish.id_shop=%d WHERE i.id_image=%d AND i.id_product=%d',
                _DB_PREFIX_, _DB_PREFIX_, $shopId, $idImage, $productId
            ), false);
            if (!$valid) {
                throw new \RuntimeException('Cannot reconcile missing product image ' . $idImage);
            }

            // image.position is global. Update it only while this image is exclusive to the
            // target shop in the same statement; shared images keep their existing global
            // position instead of relying on a stale COUNT -> UPDATE decision.
            if (!$db->execute(sprintf(
                'UPDATE `%1$simage` i ' .
                'INNER JOIN `%1$simage_shop` target ON target.id_image=i.id_image AND target.id_product=i.id_product AND target.id_shop=%2$d ' .
                'LEFT JOIN `%1$simage_shop` other ON other.id_image=i.id_image AND other.id_product=i.id_product AND other.id_shop<>%2$d ' .
                'SET i.position=%3$d WHERE i.id_image=%4$d AND i.id_product=%5$d AND other.id_image IS NULL',
                _DB_PREFIX_,
                $shopId,
                $placement['position'] + 1,
                $idImage,
                $productId
            ))) {
                throw new \RuntimeException('Cannot reconcile image position ' . $idImage);
            }
        }

        $coverId = null;
        foreach ($byImage as $placement) {
            if ($placement['is_cover']) {
                $coverId = (int) $placement['id_image'];
                break;
            }
        }
        if ($coverId === null) {
            $first = reset($byImage);
            $coverId = (int) $first['id_image'];
        }
        $this->setTargetShopCover($db, $productId, $shopId, $coverId);
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

    private function setTargetShopCover(\Db $db, int $productId, int $shopId, int $coverImageId): void
    {
        $table = _DB_PREFIX_ . 'image_shop';
        $sql = sprintf(
            'UPDATE `%1$s` current_cover INNER JOIN `%1$s` replacement ' .
            'ON replacement.id_image=%2$d AND replacement.id_product=%3$d AND replacement.id_shop=%4$d ' .
            'SET current_cover.cover=CASE WHEN current_cover.id_image=%2$d THEN 1 ELSE NULL END ' .
            'WHERE current_cover.id_product=%3$d AND current_cover.id_shop=%4$d ' .
            'AND (current_cover.cover=1 OR current_cover.id_image=%2$d)',
            $table,
            $coverImageId,
            $productId,
            $shopId
        );
        if (!$db->execute($sql)) {
            throw new \RuntimeException('Cannot set target-shop product cover');
        }

        $covers = $db->executeS(sprintf(
            'SELECT id_image FROM `%s` WHERE id_product=%d AND id_shop=%d AND cover=1 ORDER BY id_image LIMIT 2',
            $table,
            $productId,
            $shopId
        ), true, false) ?: [];
        if (count($covers) !== 1 || (int) ($covers[0]['id_image'] ?? 0) !== $coverImageId) {
            throw new \RuntimeException('Target-shop product cover could not be verified after update');
        }
    }
}
