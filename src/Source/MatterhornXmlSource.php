<?php
namespace Lp\MatterhornImport\Source;

use Prewk\XmlStringStreamer;
use SimpleXMLElement;

final class MatterhornXmlSource implements \Lp\MatterhornImport\Contract\CheckpointableSourceInterface
{
    private const FINGERPRINT_WINDOW = 65536;
    private const TAIL_WINDOW = 131072;
    private const MAX_SOURCE_RECORD_BYTES = 4194304;
    private const MAX_SOURCE_FIELD_BYTES = 2097152;
    private const MAX_SOURCE_ATTRIBUTE_BYTES = 191;
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
        $this->assertRoot($path);

        $streamer = XmlStringStreamer::createUniqueNodeParser($path, [
            'uniqueNode' => 'product',
        ]);

        $record = 0;
        while (($node = $streamer->getNode()) !== false) {
            ++$record;
            if ($record <= $offset) {
                continue;
            }

            if (strlen($node) > self::MAX_SOURCE_RECORD_BYTES) {
                throw new \RuntimeException(
                    'Matterhorn source record exceeds limit of ' . self::MAX_SOURCE_RECORD_BYTES .
                    ' bytes at source record ' . $record
                );
            }

            yield $this->parseProduct($node, $record);
        }

        $this->assertCompleteTail($path, $record);
    }

    /** @return array<string,mixed> */
    private function parseProduct(string $node, int $record): array
    {
        $oldErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $xml = simplexml_load_string(
                $node,
                SimpleXMLElement::class,
                LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'product') {
                $message = 'invalid product XML';
                $errors = libxml_get_errors();
                if ($errors !== []) {
                    $message = trim((string) end($errors)->message);
                }
                throw new \RuntimeException(
                    'Matterhorn product XML parse error at source record ' . $record . ': ' . $message
                );
            }

            $row = [
                'id' => $this->boundedAttribute($xml, 'id', $record, 'product/@id'),
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

            $seenFields = [];
            $singletonFields = [
                'name', 'creation_date', 'brand', 'category_path', 'category', 'color', 'type',
                'images', 'price', 'description', 'options',
            ];

            foreach ($xml->children() as $child) {
                $field = $child->getName();
                if (in_array($field, $singletonFields, true)) {
                    $this->assertSingletonField($seenFields, $field, $record, 'product');
                }

                if ($field === 'images') {
                    $images = $this->readImages($child, $record);
                    $row['images'] = $images['urls'];
                    $row['supplier_warnings'] = array_merge($row['supplier_warnings'], $images['warnings']);
                    continue;
                }
                if ($field === 'options') {
                    $row['options'] = $this->readOptions($child, $record);
                    continue;
                }
                if ($field === 'category') {
                    $row['category'] = [
                        'id' => $this->boundedAttribute($child, 'id', $record, 'category/@id'),
                        'name' => trim($this->scalar($child, $record, 'category')),
                    ];
                    continue;
                }
                if (in_array($field, [
                    'name', 'creation_date', 'brand', 'category_path', 'color', 'type', 'price', 'description',
                ], true)) {
                    $row[$field] = trim($this->scalar($child, $record, $field));
                }
            }

            return $row;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }
    }

    /** @return array{urls:list<string>,warnings:list<string>} */
    private function readImages(SimpleXMLElement $imagesNode, int $record): array
    {
        $urls = [];
        $warnings = [];
        $seenHashes = [];
        $imageNumber = 0;

        foreach ($imagesNode->children() as $child) {
            if ($child->getName() !== 'image_url') {
                continue;
            }

            ++$imageNumber;
            if ($imageNumber > self::MAX_IMAGES_PER_PRODUCT) {
                throw new \RuntimeException(
                    'Matterhorn product image count exceeds limit of ' . self::MAX_IMAGES_PER_PRODUCT .
                    ' at source record ' . $record
                );
            }
            if (count($child->children()) > 0) {
                throw new \RuntimeException(
                    'Matterhorn scalar field images/image_url contains nested element at source record ' . $record
                );
            }

            $url = (string) $child;
            $hash = hash('sha256', $url);
            if (isset($seenHashes[$hash])) {
                continue;
            }
            $seenHashes[$hash] = true;

            if (strlen($url) > self::MAX_IMAGE_URL_BYTES) {
                $warnings[] = 'image #' . $imageNumber . ' URL exceeds ' . self::MAX_IMAGE_URL_BYTES . ' bytes and was skipped';
                continue;
            }

            $url = trim($url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return ['urls' => $urls, 'warnings' => $warnings];
    }

    /** @return list<array<string,string>> */
    private function readOptions(SimpleXMLElement $optionsNode, int $record): array
    {
        $options = [];
        foreach ($optionsNode->children() as $child) {
            if ($child->getName() !== 'option') {
                continue;
            }
            if (count($options) >= self::MAX_OPTIONS_PER_PRODUCT) {
                throw new \RuntimeException(
                    'Matterhorn product option count exceeds limit of ' . self::MAX_OPTIONS_PER_PRODUCT .
                    ' at source record ' . $record
                );
            }
            $options[] = $this->readOption($child, $record);
        }

        return $options;
    }

    /** @return array<string,string> */
    private function readOption(SimpleXMLElement $optionNode, int $record): array
    {
        $option = [
            'id' => $this->boundedAttribute($optionNode, 'id', $record, 'options/option/@id'),
            'name' => '',
            'stock' => '',
            'available_in' => '',
            'ean' => '',
        ];
        $fieldMap = [
            'option_name' => 'name',
            'STOCK' => 'stock',
            'avaible_in' => 'available_in',
            'ean' => 'ean',
        ];
        $seenFields = [];

        foreach ($optionNode->children() as $child) {
            $rawField = $child->getName();
            if (!isset($fieldMap[$rawField])) {
                continue;
            }
            $this->assertSingletonField($seenFields, $rawField, $record, 'options/option');
            $option[$fieldMap[$rawField]] = trim($this->scalar(
                $child,
                $record,
                'options/option/' . $rawField
            ));
        }

        return $option;
    }

    private function scalar(SimpleXMLElement $node, int $record, string $field): string
    {
        if (count($node->children()) > 0) {
            $first = $node->children()[0] ?? null;
            $nested = $first instanceof SimpleXMLElement ? $first->getName() : 'unknown';
            throw new \RuntimeException(
                'Matterhorn scalar field ' . $field . ' contains nested element <' . $nested .
                '> at source record ' . $record
            );
        }

        $value = (string) $node;
        $bytes = strlen($value);
        if ($bytes > self::MAX_SOURCE_FIELD_BYTES) {
            throw new \RuntimeException(
                'Matterhorn source field ' . $field . ' exceeds limit of ' .
                self::MAX_SOURCE_FIELD_BYTES . ' bytes at source record ' . $record
            );
        }

        return $value;
    }

    private function boundedAttribute(
        SimpleXMLElement $node,
        string $attribute,
        int $record,
        string $field
    ): string {
        $value = (string) ($node->attributes()[$attribute] ?? '');
        if (strlen($value) > self::MAX_SOURCE_ATTRIBUTE_BYTES) {
            throw new \RuntimeException(
                'Matterhorn source attribute ' . $field . ' exceeds limit of ' .
                self::MAX_SOURCE_ATTRIBUTE_BYTES . ' bytes at source record ' . $record
            );
        }

        return $value;
    }

    /** @param array<string,bool> $seenFields */
    private function assertSingletonField(array &$seenFields, string $field, int $record, string $scope): void
    {
        if (isset($seenFields[$field])) {
            throw new \RuntimeException(
                'Duplicate Matterhorn singleton field ' . $scope . '/' . $field .
                ' at source record ' . $record
            );
        }
        $seenFields[$field] = true;
    }

    private function assertRoot(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open Matterhorn XML: ' . $path);
        }
        try {
            $prefix = (string) fread($handle, self::FINGERPRINT_WINDOW);
        } finally {
            fclose($handle);
        }

        $prefix = preg_replace('/^\xEF\xBB\xBF/', '', $prefix) ?? $prefix;
        $prefix = preg_replace('/^\s*<\?xml.*?\?>\s*/s', '', $prefix) ?? $prefix;
        while (preg_match('/^\s*<!--.*?-->\s*/s', $prefix, $match) === 1) {
            $prefix = substr($prefix, strlen($match[0]));
        }
        if (stripos($prefix, '<!DOCTYPE') !== false) {
            throw new \RuntimeException('Matterhorn XML DOCTYPE is not allowed');
        }
        if (preg_match('/^\s*<products(?=[\s>])/u', $prefix) !== 1) {
            throw new \RuntimeException('Matterhorn XML root must be <products>');
        }
    }

    private function assertCompleteTail(string $path, int $records): void
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('Matterhorn XML source is empty');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open Matterhorn XML tail: ' . $path);
        }
        try {
            $window = min(self::TAIL_WINDOW, (int) $size);
            if (fseek($handle, (int) $size - $window, SEEK_SET) !== 0) {
                throw new \RuntimeException('Cannot seek Matterhorn XML tail: ' . $path);
            }
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }

        if (preg_match('#</products>\s*$#sD', $tail) === 1) {
            return;
        }

        throw new \RuntimeException(
            'Unexpected EOF inside Matterhorn <product> at source record ' . ($records + 1)
        );
    }

    private function path(): string
    {
        $path = $this->explicitPath ?? (_PS_MODULE_DIR_ . 'matterhornimport/var/source.xml');
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Matterhorn source XML file is not readable: ' . $path);
        }
        return $path;
    }
}
