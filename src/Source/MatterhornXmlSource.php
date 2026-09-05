<?php
namespace Lp\MatterhornImport\Source;

use Lp\MatterhornImport\Contract\CheckpointableSourceInterface;

final class MatterhornXmlSource implements CheckpointableSourceInterface
{
    private const FINGERPRINT_WINDOW = 65536;

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
                    continue;
                }
                $xml = $reader->readOuterXML();
                if ($xml === '') {
                    continue;
                }
                $node = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
                if ($node === false) {
                    throw new \RuntimeException('Invalid Matterhorn <product> at source record ' . $seen);
                }
                yield $this->parseProduct($node);
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

    private function parseProduct(\SimpleXMLElement $node): array
    {
        $images = [];
        $seenImages = [];
        if (isset($node->images)) {
            foreach ($node->images->image_url as $imageNode) {
                $url = trim((string) $imageNode);
                if ($url === '' || isset($seenImages[$url])) {
                    continue;
                }
                $seenImages[$url] = true;
                $images[] = $url;
            }
        }

        $options = [];
        if (isset($node->options)) {
            foreach ($node->options->option as $option) {
                $availableRaw = trim((string) ($option->avaible_in ?? ''));
                $options[] = [
                    'id' => trim((string) ($option['id'] ?? '')),
                    'name' => trim((string) ($option->option_name ?? '')),
                    'stock' => trim((string) ($option->STOCK ?? '')),
                    'available_in' => $availableRaw,
                    'ean' => trim((string) ($option->ean ?? '')),
                ];
            }
        }

        return [
            'id' => trim((string) ($node['id'] ?? '')),
            'name' => trim((string) ($node->name ?? '')),
            'creation_date' => trim((string) ($node->creation_date ?? '')),
            'brand' => trim((string) ($node->brand ?? '')),
            'category_path' => trim((string) ($node->category_path ?? '')),
            'category' => [
                'id' => isset($node->category) ? trim((string) ($node->category['id'] ?? '')) : '',
                'name' => isset($node->category) ? trim((string) $node->category) : '',
            ],
            'color' => trim((string) ($node->color ?? '')),
            'type' => trim((string) ($node->type ?? '')),
            'images' => $images,
            'price' => trim((string) ($node->price ?? '')),
            'description' => trim((string) ($node->description ?? '')),
            'options' => $options,
        ];
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
