<?php
namespace Lp\MatterhornImport\Source;

use Prewk\XmlStringStreamer\Parser\StringWalker;

/**
 * Thin instrumentation wrapper around Prewk's StringWalker.
 *
 * XML tokenization remains entirely inside prewk/xml-string-streamer; this class
 * only exposes the number of unread bytes so the AJAX importer can persist an
 * exact crash-safe byte cursor after each committed DB batch.
 */
final class PrewkCheckpointStringWalker extends StringWalker
{
    public function unreadBytes(): int
    {
        return strlen($this->chunk);
    }
}
