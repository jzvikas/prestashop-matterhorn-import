<?php
namespace Lp\MatterhornImport\Source;

use Lp\MatterhornImport\Contract\CheckpointableSourceInterface;

final class MatterhornXmlSource implements CheckpointableSourceInterface
{
    private const FINGERPRINT_WINDOW = 65536;
    private const MAX_SOURCE_RECORD_BYTES = 4194304; // 4 MiB decoded text per product
    private const MAX_SOURCE_FIELD_BYTES = 2097152; // 2 MiB per scalar field
    private const MAX_IMAGE_URL_BYTES = 16384;
    private const MAX_IMAGES_PER_PRODUCT = 1000;
    private const MAX_OPTIONS_PER_PRODUCT = 5000;

    public function __construct(private readonly ?string $explicitPath = null)
    {
    }

    public function name(): string
    {
        return 'matterhorn';
    }

    public function rows(): iterable
    {
        yield from $this->rowsFrom(0);
    }

    public function fingerprint(): string
    {
        $path = $this->path();
        clearstatcache(true, $path);
        $real = realpath($path);
        $stat = stat($path);
        if ($real === false || $stat === false) {
            throw new \RuntimeException('Cannot stat Matterhorn XML: ' . $path);
        }
        $size = (int) $stat['size'];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open Matterhorn XML for fingerprint: ' . $path);
        }
        try {
            $first = (string) fread($handle, self::FINGERPRINT_WINDOW);
            $last = '';
            if ($size > self::FINGERPRINT_WINDOW) {
                if (fseek($handle, max(0, $size - self::FINGERPRINT_WINDOW), SEEK_SET) !== 0) {
                    throw new \RuntimeException('Cannot seek Matterhorn XML for fingerprint: ' . $path);
                }
                $last = (string) fread($handle, self::FINGERPRINT_WINDOW);
            }
            return hash('sha256', implode('|', [
                $real,
                (string) $size,
                (string) ($stat['mtime'] ?? 0),
                (string) ($stat['ctime'] ?? 0),
                (string) ($stat['dev'] ?? 0),
                (string) ($stat['ino'] ?? 0),
                hash('sha256', $first),
                hash('sha256', $last),
            ]));
        } finally {
            fclose($handle);
        }
    }

    public function rowsFrom(int $offset): iterable
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Matterhorn XML row offset cannot be negative');
        }
        $path = $this->path();
        $oldErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($oldErrors);
            throw new \RuntimeException('Cannot open Matterhorn XML: ' . $path);
        }
        $seen = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'product') {
                    continue;
                }
                $seen++;
                if ($seen <= $offset) {
                    $this->skipCurrentElement($reader);
                    continue;
                }
                yield $this->readProduct($reader, $seen);
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new \RuntimeException('Matterhorn XML parse error: ' . trim($error->message));
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }
    }

    /** @return array<string,mixed> */
    private function readProduct(\XMLReader $reader, int $record): array
    {
        $row = [
            'id' => trim((string) $reader->getAttribute('id')),
            'name' => '',
            'creation_date' => '',
            'brand' => '',
            'category_path' => '',
            'category' => ['id' => '', 'name' => ''],
            'color' => '',
            'type' => '',
            'images' => [],
            'price' => '',
            'description' => '',
            'options' => [],
            'supplier_warnings' => [],
        ];
        if ($reader->isEmptyElement) {
            return $row;
        }

        $productDepth = $reader->depth;
        $recordBytes = 0;
        while ($reader->read()) {
            if (
                $reader->nodeType === \XMLReader::END_ELEMENT
                && $reader->depth === $productDepth
                && $reader->localName === 'product'
            ) {
                return $row;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $productDepth + 1) {
                continue;
            }

            $field = $reader->localName;
            if ($field === 'images') {
                $imageResult = $this->readImages($reader, $record, $recordBytes);
                $row['images'] = $imageResult['urls'];
                $row['supplier_warnings'] = array_merge($row['supplier_warnings'], $imageResult['warnings']);
                continue;
            }
            if ($field === 'options') {
                $row['options'] = $this->readOptions($reader, $record, $recordBytes);
                continue;
            }
            if ($field === 'category') {
                $row['category'] = [
                    'id' => trim((string) $reader->getAttribute('id')),
                    'name' => trim($this->readScalarElement(
                        $reader,
                        $record,
                        'category',
                        self::MAX_SOURCE_FIELD_BYTES,
                        $recordBytes
                    )),
                ];
                continue;
            }
            if (in_array($field, [
                'name', 'creation_date', 'brand', 'category_path', 'color', 'type', 'price', 'description',
            ], true)) {
                $row[$field] = trim($this->readScalarElement(
                    $reader,
                    $record,
                    $field,
                    self::MAX_SOURCE_FIELD_BYTES,
                    $recordBytes
                ));
                continue;
            }

            $this->skipCurrentElementCounting($reader, $record, $field, $recordBytes);
        }

        throw new \RuntimeException('Unexpected EOF inside Matterhorn <product> at source record ' . $record);
    }

    /** @return array{urls:list<string>,warnings:list<string>} */
    private function readImages(\XMLReader $reader, int $record, int &$recordBytes): array
    {
        if ($reader->isEmptyElement) {
            return ['urls' => [], 'warnings' => []];
        }
        $depth = $reader->depth;
        $images = [];
        $warnings = [];
        $seenHashes = [];
        $imageNumber = 0;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'images') {
                return ['urls' => $images, 'warnings' => $warnings];
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }
            if ($reader->localName !== 'image_url') {
                $this->skipCurrentElementCounting($reader, $record, 'images/' . $reader->localName, $recordBytes);
                continue;
            }
            $imageNumber++;
            if ($imageNumber > self::MAX_IMAGES_PER_PRODUCT) {
                throw new \RuntimeException(
                    'Matterhorn product image count exceeds limit of ' . self::MAX_IMAGES_PER_PRODUCT .
                    ' at source record ' . $record
                );
            }
            $result = $this->readImageUrlElement($reader, $record, $recordBytes);
            if (isset($seenHashes[$result['hash']])) {
                continue;
            }
            $seenHashes[$result['hash']] = true;
            if ($result['oversized']) {
                $warnings[] = 'image #' . $imageNumber . ' URL exceeds ' . self::MAX_IMAGE_URL_BYTES . ' bytes and was skipped';
                continue;
            }
            $url = trim($result['url']);
            if ($url !== '') {
                $images[] = $url;
            }
        }
        throw new \RuntimeException('Unexpected EOF inside Matterhorn <images> at source record ' . $record);
    }

    /** @return array{url:string,hash:string,oversized:bool} */
    private function readImageUrlElement(\XMLReader $reader, int $record, int &$recordBytes): array
    {
        if ($reader->isEmptyElement) {
            return ['url' => '', 'hash' => hash('sha256', ''), 'oversized' => false];
        }
        $depth = $reader->depth;
        $name = $reader->localName;
        $value = '';
        $fieldBytes = 0;
        $oversized = false;
        $hash = hash_init('sha256');
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $name) {
                return ['url' => $value, 'hash' => hash_final($hash), 'oversized' => $oversized];
            }
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                throw new \RuntimeException(
                    'Matterhorn scalar field images/image_url contains nested element <' . $reader->localName .
                    '> at source record ' . $record
                );
            }
            if (!in_array($reader->nodeType, [
                \XMLReader::TEXT,
                \XMLReader::CDATA,
                \XMLReader::WHITESPACE,
                \XMLReader::SIGNIFICANT_WHITESPACE,
            ], true)) {
                continue;
            }
            $chunk = $reader->value;
            $chunkBytes = strlen($chunk);
            $fieldBytes += $chunkBytes;
            $recordBytes += $chunkBytes;
            hash_update($hash, $chunk);
            $this->assertRecordBytes($recordBytes, $record);
            if (!$oversized && $fieldBytes <= self::MAX_IMAGE_URL_BYTES) {
                $value .= $chunk;
            } elseif (!$oversized) {
                $oversized = true;
                $value = '';
            }
        }
        throw new \RuntimeException('Unexpected EOF inside Matterhorn <image_url> at source record ' . $record);
    }

    /** @return list<array<string,string>> */
    private function readOptions(\XMLReader $reader, int $record, int &$recordBytes): array
    {
        if ($reader->isEmptyElement) {
            return [];
        }
        $depth = $reader->depth;
        $options = [];
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'options') {
                return $options;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }
            if ($reader->localName !== 'option') {
                $this->skipCurrentElementCounting($reader, $record, 'options/' . $reader->localName, $recordBytes);
                continue;
            }
            if (count($options) >= self::MAX_OPTIONS_PER_PRODUCT) {
                throw new \RuntimeException(
                    'Matterhorn product option count exceeds limit of ' . self::MAX_OPTIONS_PER_PRODUCT .
                    ' at source record ' . $record
                );
            }
            $options[] = $this->readOption($reader, $record, $recordBytes);
        }
        throw new \RuntimeException('Unexpected EOF inside Matterhorn <options> at source record ' . $record);
    }

    /** @return array<string,string> */
    private function readOption(\XMLReader $reader, int $record, int &$recordBytes): array
    {
        $option = [
            'id' => trim((string) $reader->getAttribute('id')),
            'name' => '',
            'stock' => '',
            'available_in' => '',
            'ean' => '',
        ];
        if ($reader->isEmptyElement) {
            return $option;
        }
        $depth = $reader->depth;
        $fieldMap = [
            'option_name' => 'name',
            'STOCK' => 'stock',
            'avaible_in' => 'available_in',
            'ean' => 'ean',
        ];
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'option') {
                return $option;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }
            $rawField = $reader->localName;
            if (!isset($fieldMap[$rawField])) {
                $this->skipCurrentElementCounting($reader, $record, 'options/option/' . $rawField, $recordBytes);
                continue;
            }
            $option[$fieldMap[$rawField]] = trim($this->readScalarElement(
                $reader,
                $record,
                'options/option/' . $rawField,
                self::MAX_SOURCE_FIELD_BYTES,
                $recordBytes
            ));
        }
        throw new \RuntimeException('Unexpected EOF inside Matterhorn <option> at source record ' . $record);
    }

    private function readScalarElement(
        \XMLReader $reader,
        int $record,
        string $field,
        int $fieldLimit,
        int &$recordBytes
    ): string {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $name = $reader->localName;
        $value = '';
        $fieldBytes = 0;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $name) {
                return $value;
            }
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                throw new \RuntimeException(
                    'Matterhorn scalar field ' . $field . ' contains nested element <' . $reader->localName .
                    '> at source record ' . $record
                );
            }
            if (!in_array($reader->nodeType, [
                \XMLReader::TEXT,
                \XMLReader::CDATA,
                \XMLReader::WHITESPACE,
                \XMLReader::SIGNIFICANT_WHITESPACE,
            ], true)) {
                continue;
            }
            $chunk = $reader->value;
            $chunkBytes = strlen($chunk);
            $fieldBytes += $chunkBytes;
            $recordBytes += $chunkBytes;
            if ($fieldBytes > $fieldLimit) {
                throw new \RuntimeException(
                    'Matterhorn source field ' . $field . ' exceeds limit of ' . $fieldLimit .
                    ' bytes at source record ' . $record
                );
            }
            $this->assertRecordBytes($recordBytes, $record);
            $value .= $chunk;
        }
        throw new \RuntimeException(
            'Unexpected EOF inside Matterhorn <' . $name . '> at source record ' . $record
        );
    }

    private function skipCurrentElementCounting(
        \XMLReader $reader,
        int $record,
        string $field,
        int &$recordBytes
    ): void {
        if ($reader->isEmptyElement) {
            return;
        }
        $depth = $reader->depth;
        $name = $reader->localName;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $name) {
                return;
            }
            if (in_array($reader->nodeType, [
                \XMLReader::TEXT,
                \XMLReader::CDATA,
                \XMLReader::WHITESPACE,
                \XMLReader::SIGNIFICANT_WHITESPACE,
            ], true)) {
                $recordBytes += strlen($reader->value);
                $this->assertRecordBytes($recordBytes, $record);
            }
        }
        throw new \RuntimeException(
            'Unexpected EOF inside ignored Matterhorn field ' . $field . ' at source record ' . $record
        );
    }

    private function assertRecordBytes(int $recordBytes, int $record): void
    {
        if ($recordBytes > self::MAX_SOURCE_RECORD_BYTES) {
            throw new \RuntimeException(
                'Matterhorn source product text exceeds limit of ' . self::MAX_SOURCE_RECORD_BYTES .
                ' bytes at source record ' . $record
            );
        }
    }

    private function skipCurrentElement(\XMLReader $reader): void
    {
        if ($reader->isEmptyElement) {
            return;
        }
        $depth = $reader->depth;
        $name = $reader->localName;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $name) {
                return;
            }
        }
    }

    private function path(): string
    {
        if ($this->explicitPath !== null && $this->explicitPath !== '') {
            $path = $this->explicitPath;
        } else {
            if (!class_exists('Context') || !class_exists('Configuration') || !class_exists('Shop')) {
                throw new \RuntimeException('Matterhorn source path requires PrestaShop context or an explicit test path');
            }
            $context = \Context::getContext();
            $shop = $context->shop ?? null;
            if (!$shop instanceof \Shop || (int) ($shop->id ?? 0) <= 0) {
                throw new \RuntimeException('Matterhorn source configuration requires an explicit shop context');
            }
            $shopId = (int) $shop->id;
            $shopGroupId = (int) $shop->id_shop_group;
            if ($shopGroupId <= 0) {
                throw new \RuntimeException('Matterhorn source configuration requires a valid shop group');
            }
            $path = (string) \Configuration::get('MATTERHORNIMPORT_SOURCE_FILE', null, $shopGroupId, $shopId);
            if ($path === '') {
                $path = _PS_MODULE_DIR_ . 'matterhornimport/var/source.xml';
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Matterhorn XML is not readable: ' . $path);
        }
        return $path;
    }
}
