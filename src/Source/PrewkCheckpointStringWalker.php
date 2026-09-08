<?php
namespace Lp\MatterhornImport\Source;

use Prewk\XmlStringStreamer\Parser\StringWalker;
use Prewk\XmlStringStreamer\StreamInterface;

/**
 * Thin instrumentation/recovery wrapper around Prewk's StringWalker.
 *
 * XML tokenization remains entirely inside prewk/xml-string-streamer. Besides
 * exposing the unread byte count for exact checkpoints, this wrapper makes the
 * supplier's top-level <product> boundary authoritative. That lets one malformed
 * child (for example an unclosed <description>) be rejected as one product
 * without StringWalker's depth counter swallowing the following product too.
 * CDATA/comments remain atomic Prewk tokens, so literal </product> text inside
 * those sections does not trigger recovery.
 */
final class PrewkCheckpointStringWalker extends StringWalker
{
    public function unreadBytes(): int
    {
        return strlen($this->chunk);
    }

    /**
     * @return string|bool
     */
    public function getNodeFrom(StreamInterface $stream)
    {
        while ($this->prepareChunk($stream)) {
            $this->firstRun = false;

            while ($shaved = $this->shave()) {
                [$element, $data] = $shaved;

                // If a malformed product forgot one or more child closing tags,
                // StringWalker's generic depth would otherwise keep capturing into
                // the next records. A top-level Matterhorn product close is always
                // the record boundary, regardless of malformed child depth.
                if ($this->capture && $this->isProductClose($element)) {
                    $this->depth = $this->options['captureDepth'] - 1;
                    $this->capture = false;
                    $this->shaved .= $data;

                    $node = $this->shaved;
                    $this->shaved = null;
                    return $node;
                }

                // Missing </product>: if the next top-level product starts while
                // still capturing, finish the broken record before that opening
                // tag and put the opening token back for the next getNode() call.
                if ($this->capture && $this->isProductOpen($element)) {
                    $prefixBytes = strlen($data) - strlen($element);
                    if ($prefixBytes > 0) {
                        $this->shaved .= substr($data, 0, $prefixBytes);
                    }
                    $this->chunk = $element . $this->chunk;
                    $this->depth = $this->options['captureDepth'] - 1;
                    $this->capture = false;

                    $node = $this->shaved;
                    $this->shaved = null;
                    return $node;
                }

                $edge = $this->getEdges($element);
                if (!is_array($edge) || count($edge) < 3) {
                    continue;
                }
                [, , $depth] = $edge;
                $this->depth += $depth;

                $flush = false;
                $captureOnce = false;

                if ($this->depth === $this->options['captureDepth'] && $depth > 0) {
                    $this->capture = true;
                } elseif ($this->depth === $this->options['captureDepth'] - 1 && $depth < 0) {
                    $flush = true;
                    $this->capture = false;
                    $this->shaved .= $data;
                } elseif ($this->options['extractContainer'] && $this->depth < $this->options['captureDepth']) {
                    $this->containerXml .= $element;
                } elseif ($depth === 0 && $this->depth + 1 === $this->options['captureDepth']) {
                    $captureOnce = true;
                    $flush = true;
                }

                if ($this->capture || $captureOnce) {
                    $this->shaved .= $data;
                }

                if ($flush) {
                    $node = $this->shaved;
                    $this->shaved = null;
                    return $node;
                }
            }
        }

        return false;
    }

    private function isProductOpen(string $element): bool
    {
        return preg_match('/^<product(?=[\\s>])/i', $element) === 1;
    }

    private function isProductClose(string $element): bool
    {
        return preg_match('/^<\\/product\\s*>$/i', $element) === 1;
    }
}
