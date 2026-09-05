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

    public function transferCover(int $oldImageId, int $newImageId, int $productId, int $shopId): void
    {
        if ($oldImageId <= 0 || $newImageId <= 0 || $productId <= 0 || $shopId <= 0 || $oldImageId === $newImageId) { return; }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $valid = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage` WHERE id_product=%d AND id_image IN (%d,%d)', _DB_PREFIX_, $productId, $oldImageId, $newImageId));
        if ($valid !== 2) { throw new \RuntimeException('Cannot transfer cover between invalid product images'); }
        if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=NULL WHERE id_product=%d AND id_shop=%d AND cover=1', _DB_PREFIX_, $productId, $shopId))) {
            throw new \RuntimeException('Cannot clear previous target-shop cover');
        }
        if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=1 WHERE id_image=%d AND id_product=%d AND id_shop=%d', _DB_PREFIX_, $newImageId, $productId, $shopId))) {
            throw new \RuntimeException('Cannot set replacement target-shop cover');
        }
    }

    public function deleteImage(int $idImage, int $productId, int $shopId): bool
    {
        if ($idImage <= 0 || $productId <= 0 || $shopId <= 0) { return false; }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $image = new \Image($idImage);
        if (!\Validate::isLoadedObject($image) || (int) $image->id_product !== $productId) { return false; }
        $shopRows = $db->executeS(sprintf('SELECT id_shop FROM `%simage_shop` WHERE id_image=%d AND id_product=%d ORDER BY id_shop', _DB_PREFIX_, $idImage, $productId)) ?: [];
        if (count($shopRows) !== 1 || (int) $shopRows[0]['id_shop'] !== $shopId) { return false; }
        if (!$image->delete()) { throw new \RuntimeException('Cannot delete image ' . $idImage); }
        return true;
    }

    /** @param list<array{id_image:int,position:int,is_cover:bool}> $placements */
    public function syncProductPlacement(int $productId, int $shopId, array $placements): void
    {
        if ($productId <= 0 || $shopId <= 0) { throw new \InvalidArgumentException('Invalid image placement context'); }
        $this->shopContext->activate($shopId);
        $db = \Db::getInstance();
        $byImage = [];
        foreach ($placements as $placement) {
            $idImage = (int) ($placement['id_image'] ?? 0);
            if ($idImage <= 0) { continue; }
            $position = max(0, (int) ($placement['position'] ?? 0));
            if (!isset($byImage[$idImage]) || $position < $byImage[$idImage]['position']) {
                $byImage[$idImage] = ['id_image'=>$idImage,'position'=>$position,'is_cover'=>(bool)($placement['is_cover'] ?? false)];
            } elseif ((bool) ($placement['is_cover'] ?? false)) {
                $byImage[$idImage]['is_cover'] = true;
            }
        }
        uasort($byImage, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach ($byImage as $idImage => $placement) {
            $valid = (bool) $db->getValue(sprintf('SELECT 1 FROM `%simage` i INNER JOIN `%simage_shop` ish ON ish.id_image=i.id_image AND ish.id_shop=%d WHERE i.id_image=%d AND i.id_product=%d', _DB_PREFIX_, _DB_PREFIX_, $shopId, $idImage, $productId));
            if (!$valid) { throw new \RuntimeException('Cannot reconcile missing product image ' . $idImage); }
            $shopCount = (int) $db->getValue(sprintf('SELECT COUNT(*) FROM `%simage_shop` WHERE id_image=%d', _DB_PREFIX_, $idImage));
            if ($shopCount === 1 && !$db->execute(sprintf('UPDATE `%simage` SET position=%d WHERE id_image=%d AND id_product=%d', _DB_PREFIX_, $placement['position'] + 1, $idImage, $productId))) {
                throw new \RuntimeException('Cannot reconcile image position ' . $idImage);
            }
        }
        if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=NULL WHERE id_product=%d AND id_shop=%d AND cover=1', _DB_PREFIX_, $productId, $shopId))) {
            throw new \RuntimeException('Cannot clear target-shop cover during reconciliation');
        }
        if ($byImage) {
            $coverId = null;
            foreach ($byImage as $placement) { if ($placement['is_cover']) { $coverId = (int) $placement['id_image']; break; } }
            if ($coverId === null) { $first = reset($byImage); $coverId = (int) $first['id_image']; }
            if (!$db->execute(sprintf('UPDATE `%simage_shop` SET cover=1 WHERE id_image=%d AND id_product=%d AND id_shop=%d', _DB_PREFIX_, $coverId, $productId, $shopId))) {
                throw new \RuntimeException('Cannot set reconciled target-shop cover');
            }
        }
    }
}
