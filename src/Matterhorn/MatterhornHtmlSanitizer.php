<?php
namespace Lp\MatterhornImport\Matterhorn;

final class MatterhornHtmlSanitizer
{
    private const ALLOWED_TAGS = ['p','br','strong','b','em','i','ul','ol','li','div','span','table','thead','tbody','tr','th','td'];
    private const ALLOWED_ATTRS = ['class','colspan','rowspan','align'];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (class_exists('Tools') && method_exists('Tools', 'purifyHTML')) {
            return trim((string) \Tools::purifyHTML($html));
        }
        if (!class_exists('DOMDocument')) {
            return trim(strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><div><span><table><thead><tbody><tr><th><td>'));
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $wrapped = '<div id="matterhorn-root">' . $html . '</div>';
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $wrapped,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
            if (!$loaded) {
                return '';
            }
            $root = $dom->getElementById('matterhorn-root');
            if (!$root instanceof \DOMElement) {
                return '';
            }
            $this->sanitizeChildren($root);
            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child) ?: '';
            }
            return trim($out);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sanitizeChildren(\DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof \DOMElement) {
                $tag = strtolower($node->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                    $attr = $node->attributes->item($i);
                    if ($attr !== null && !in_array(strtolower($attr->name), self::ALLOWED_ATTRS, true)) {
                        $node->removeAttributeNode($attr);
                    }
                }
                $this->sanitizeChildren($node);
            } elseif (!($node instanceof \DOMText)) {
                $parent->removeChild($node);
            }
            $node = $next;
        }
    }
}
