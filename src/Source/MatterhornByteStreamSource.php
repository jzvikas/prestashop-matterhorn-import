<?php
namespace Lp\MatterhornImport\Source;

use Lp\MatterhornImport\Contract\ByteCheckpointableSourceInterface;

final class MatterhornByteStreamSource implements ByteCheckpointableSourceInterface
{
    private const READ_CHUNK_BYTES = 65536;
    private const PARSE_CHUNK_RECORDS = 64;
    private const PARSE_CHUNK_BYTES = 8388608;
    private const MAX_RAW_PRODUCT_BYTES = 8388608;
    private const PRODUCT_END = '</product>';

    private int $byteCheckpoint = 0;

    public function __construct(private readonly string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Matterhorn run source file is not readable: ' . $path);
        }
    }

    public function name(): string
    {
        return 'matterhorn';
    }

    public function rows(): iterable
    {
        yield from $this->rowsFromByte(0, 0);
    }

    /**
     * Backward-compatible record checkpoint path.
     *
     * Existing paused runs created before byte checkpoints were introduced only
     * know the number of consumed products. We raw-scan those records once and
     * then expose a byte checkpoint so every following AJAX request can seek
     * directly to the next product.
     */
    public function rowsFrom(int $offset): iterable
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Matterhorn XML row offset cannot be negative');
        }

        yield from $this->stream(0, $offset, $offset);
    }

    public function rowsFromByte(int $byteOffset, int $recordOffset = 0): iterable
    {
        if ($byteOffset < 0 || $recordOffset < 0) {
            throw new \InvalidArgumentException('Matterhorn XML byte/record checkpoint cannot be negative');
        }

        yield from $this->stream($byteOffset, 0, $recordOffset);
    }

    public function byteCheckpoint(): int
    {
        return $this->byteCheckpoint;
    }

    public function fingerprint(): string
    {
        return (new MatterhornXmlSource($this->path))->fingerprint();
    }

    private function stream(int $byteOffset, int $skipRecords, int $recordOffset): iterable
    {
        $this->assertOffset($byteOffset);
        if ($byteOffset === 0) {
            $this->assertRoot();
        }

        $fragments = $this->fragments($byteOffset);
        $skipped = 0;
        while ($skipped < $skipRecords && $fragments->valid()) {
            $fragment = $fragments->current();
            $this->byteCheckpoint = (int) $fragment['next_offset'];
            ++$skipped;
            $fragments->next();
        }
        if ($skipped !== $skipRecords) {
            throw new \RuntimeException(sprintf(
                'Matterhorn READ checkpoint %d exceeds available source records',
                $skipRecords
            ));
        }

        $absoluteRecord = $recordOffset;
        while ($fragments->valid()) {
            $chunk = [];
            $chunkBytes = 0;
            while ($fragments->valid() && count($chunk) < self::PARSE_CHUNK_RECORDS) {
                $fragment = $fragments->current();
                $fragmentBytes = strlen((string) $fragment['xml']);
                if ($chunk !== [] && $chunkBytes + $fragmentBytes > self::PARSE_CHUNK_BYTES) {
                    break;
                }
                $chunk[] = $fragment;
                $chunkBytes += $fragmentBytes;
                $fragments->next();
            }

            foreach ($this->parseChunk($chunk, $absoluteRecord) as $index => $row) {
                ++$absoluteRecord;
                $this->byteCheckpoint = (int) $chunk[$index]['next_offset'];
                yield $row;
            }
        }
    }

    /**
     * @return \Generator<int,array{xml:string,next_offset:int}>
     */
    private function fragments(int $byteOffset): \Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open frozen Matterhorn source: ' . $this->path);
        }
        if ($byteOffset > 0 && fseek($handle, $byteOffset, SEEK_SET) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Cannot seek frozen Matterhorn source to byte ' . $byteOffset);
        }

        $buffer = '';
        $bufferStart = $byteOffset;
        $insideProduct = false;
        $rootClosed = false;

        try {
            while (true) {
                if (!$insideProduct) {
                    $start = $this->productStartOffset($buffer);
                    if ($start !== null) {
                        if ($start > 0) {
                            $prefix = substr($buffer, 0, $start);
                            if (str_contains($prefix, '</products>')) {
                                $rootClosed = true;
                                return;
                            }
                            $buffer = substr($buffer, $start);
                            $bufferStart += $start;
                        }
                        $insideProduct = true;
                    }
                }

                if ($insideProduct) {
                    $end = strpos($buffer, self::PRODUCT_END);
                    if ($end !== false) {
                        $end += strlen(self::PRODUCT_END);
                        $fragment = substr($buffer, 0, $end);
                        if (strlen($fragment) > self::MAX_RAW_PRODUCT_BYTES) {
                            throw new \RuntimeException(
                                'Raw Matterhorn <product> fragment exceeds byte-stream safety limit of ' .
                                self::MAX_RAW_PRODUCT_BYTES . ' bytes'
                            );
                        }
                        $nextOffset = $bufferStart + $end;
                        yield ['xml' => $fragment, 'next_offset' => $nextOffset];
                        $buffer = substr($buffer, $end);
                        $bufferStart = $nextOffset;
                        $insideProduct = false;
                        continue;
                    }

                    if (strlen($buffer) > self::MAX_RAW_PRODUCT_BYTES) {
                        throw new \RuntimeException(
                            'Raw Matterhorn <product> fragment exceeds byte-stream safety limit of ' .
                            self::MAX_RAW_PRODUCT_BYTES . ' bytes'
                        );
                    }
                } elseif (str_contains($buffer, '</products>')) {
                    $rootClosed = true;
                    return;
                }

                if (feof($handle)) {
                    break;
                }

                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read frozen Matterhorn source stream');
                }
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;

                if (!$insideProduct && strlen($buffer) > self::READ_CHUNK_BYTES * 2) {
                    // Preserve enough tail bytes for a tag split across fread() boundaries.
                    $keep = 64;
                    $discard = strlen($buffer) - $keep;
                    $discarded = substr($buffer, 0, $discard);
                    if (str_contains($discarded, '</products>')) {
                        $rootClosed = true;
                        return;
                    }
                    $buffer = substr($buffer, -$keep);
                    $bufferStart += $discard;
                }
            }

            if ($insideProduct) {
                throw new \RuntimeException(
                    'Unexpected EOF inside Matterhorn <product> near byte ' . $bufferStart
                );
            }
            if (!$rootClosed && !str_contains($buffer, '</products>')) {
                throw new \RuntimeException('Matterhorn XML parse error: unexpected EOF before </products>');
            }
        } finally {
            fclose($handle);
        }
    }

    private function productStartOffset(string $buffer): ?int
    {
        if (preg_match('/<product(?=[\s>\/])/u', $buffer, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return (int) $match[0][1];
    }

    /**
     * @param list<array{xml:string,next_offset:int}> $chunk
     * @return \Generator<int,array<string,mixed>>
     */
    private function parseChunk(array $chunk, int $recordOffset): \Generator
    {
        if ($chunk === []) {
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'matterhorn-read-');
        if ($temp === false) {
            throw new \RuntimeException('Could not allocate temporary Matterhorn parse chunk');
        }

        try {
            $handle = fopen($temp, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Could not open temporary Matterhorn parse chunk');
            }
            try {
                $this->writeAll($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<products>\n");
                foreach ($chunk as $fragment) {
                    $this->writeAll($handle, (string) $fragment['xml']);
                    $this->writeAll($handle, "\n");
                }
                $this->writeAll($handle, "</products>\n");
                fflush($handle);
            } finally {
                fclose($handle);
            }

            $index = 0;
            try {
                foreach ((new MatterhornXmlSource($temp))->rows() as $row) {
                    if (!array_key_exists($index, $chunk)) {
                        throw new \RuntimeException('Matterhorn byte-stream parser emitted more rows than source fragments');
                    }
                    yield $index => $row;
                    ++$index;
                }
            } catch (\RuntimeException $exception) {
                throw $this->withAbsoluteRecord($exception, $recordOffset);
            }

            if ($index !== count($chunk)) {
                throw new \RuntimeException(sprintf(
                    'Matterhorn byte-stream parser emitted %d rows for %d source fragments',
                    $index,
                    count($chunk)
                ));
            }
        } finally {
            @unlink($temp);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write temporary Matterhorn parse chunk');
            }
            $offset += $written;
        }
    }

    private function withAbsoluteRecord(\RuntimeException $exception, int $recordOffset): \RuntimeException
    {
        $message = preg_replace_callback(
            '/source record (\d+)/',
            static fn(array $match): string => 'source record ' . ($recordOffset + (int) $match[1]),
            $exception->getMessage()
        );

        return new \RuntimeException($message ?? $exception->getMessage(), 0, $exception);
    }

    private function assertOffset(int $byteOffset): void
    {
        clearstatcache(true, $this->path);
        $size = filesize($this->path);
        if ($size === false) {
            throw new \RuntimeException('Cannot stat frozen Matterhorn source: ' . $this->path);
        }
        if ($byteOffset > (int) $size) {
            throw new \RuntimeException(sprintf(
                'Matterhorn byte checkpoint %d exceeds source size %d',
                $byteOffset,
                (int) $size
            ));
        }
    }

    private function assertRoot(): void
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open frozen Matterhorn source for root validation');
        }
        try {
            $prefix = (string) fread($handle, self::READ_CHUNK_BYTES);
        } finally {
            fclose($handle);
        }
        $prefix = preg_replace('/^\xEF\xBB\xBF/', '', $prefix) ?? $prefix;
        $prefix = preg_replace('/^\s*<\?xml[^?]*\?>\s*/s', '', $prefix) ?? $prefix;
        if (preg_match('/^\s*<products(?=[\s>])/u', $prefix) !== 1) {
            throw new \RuntimeException('Matterhorn XML root must be <products>');
        }
    }
}
