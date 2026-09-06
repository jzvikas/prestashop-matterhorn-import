<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lp\MatterhornImport\Matterhorn\MatterhornHtmlSanitizer;

function htmlSanitizerCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$sanitizer = new MatterhornHtmlSanitizer();

$nested = $sanitizer->sanitize(
    '<section><img src="x" onerror="alert(1)">' .
    '<div class="safe" onclick="alert(2)"><strong style="color:red">OK</strong></div>' .
    '</section>'
);
htmlSanitizerCheck(!str_contains(strtolower($nested), '<img'), 'nested disallowed img must not survive wrapper unwrapping');
htmlSanitizerCheck(!str_contains(strtolower($nested), 'onerror='), 'nested event handler must not survive sanitization');
htmlSanitizerCheck(!str_contains(strtolower($nested), 'onclick='), 'allowed descendant event handler must be stripped');
htmlSanitizerCheck(!str_contains(strtolower($nested), 'style='), 'non-allowlisted descendant attribute must be stripped');
htmlSanitizerCheck(str_contains($nested, '<div class="safe"><strong>OK</strong></div>'), 'safe descendants must remain after disallowed wrapper removal');

$deepNested = $sanitizer->sanitize(
    '<section><article><iframe src="https://evil.test/"></iframe>' .
    '<span data-extra="x">Text</span></article></section>'
);
htmlSanitizerCheck(!str_contains(strtolower($deepNested), '<iframe'), 'deep nested iframe must not survive sanitization');
htmlSanitizerCheck(!str_contains(strtolower($deepNested), 'data-extra='), 'deep nested non-allowlisted attribute must be stripped');
htmlSanitizerCheck(str_contains($deepNested, '<span>Text</span>'), 'safe deep descendant text must be preserved');

$table = $sanitizer->sanitize(
    '<custom><table class="spec"><tr><td colspan="2" onmouseover="x">Value</td></tr></table></custom>'
);
htmlSanitizerCheck(str_contains($table, '<table class="spec">'), 'allowed table must survive disallowed wrapper removal');
htmlSanitizerCheck(str_contains($table, 'colspan="2"'), 'allowlisted table attributes must survive');
htmlSanitizerCheck(!str_contains(strtolower($table), 'onmouseover='), 'event handler on preserved table descendant must be stripped');

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/src/Matterhorn/MatterhornHtmlSanitizer.php');
htmlSanitizerCheck(
    str_contains($sourceCode, '$this->sanitizeChildren($node);'),
    'disallowed wrappers must sanitize descendants before unwrapping'
);

echo "Matterhorn HTML sanitizer: OK\n";
