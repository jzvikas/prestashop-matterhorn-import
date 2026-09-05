#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

python3 - "$ROOT" <<'PY'
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
paths = list((root / 'src').rglob('*.php'))
paths.extend([
    root / 'matterhornimport.php',
    root / 'tests' / 'prestashop-domain-runtime.php',
])


def call_bodies(text: str, method: str):
    """Yield (call_start, body) for balanced getValue/getRow calls.

    A regex ending at the first ')' is unsafe here because PHP SQL expressions commonly
    contain nested pSQL()/sprintf() calls. Track balanced parentheses while ignoring
    quoted strings and comments so the release guard sees the complete DB call.
    """
    pattern = re.compile(rf'\b{re.escape(method)}\s*\(', re.IGNORECASE)
    for match in pattern.finditer(text):
        open_paren = text.find('(', match.start(), match.end())
        if open_paren < 0:
            continue
        depth = 1
        index = open_paren + 1
        quote = None
        escaped = False
        line_comment = False
        block_comment = False
        while index < len(text):
            char = text[index]
            nxt = text[index + 1] if index + 1 < len(text) else ''

            if line_comment:
                if char == '\n':
                    line_comment = False
                index += 1
                continue
            if block_comment:
                if char == '*' and nxt == '/':
                    block_comment = False
                    index += 2
                else:
                    index += 1
                continue
            if quote is not None:
                if escaped:
                    escaped = False
                elif char == '\\':
                    escaped = True
                elif char == quote:
                    quote = None
                index += 1
                continue

            if char in ("'", '"'):
                quote = char
                index += 1
                continue
            if char == '/' and nxt == '/':
                line_comment = True
                index += 2
                continue
            if char == '/' and nxt == '*':
                block_comment = True
                index += 2
                continue
            if char == '#':
                line_comment = True
                index += 1
                continue
            if char == '(':
                depth += 1
            elif char == ')':
                depth -= 1
                if depth == 0:
                    yield match.start(), text[open_paren + 1:index]
                    break
            index += 1


# Guard the guard: nested pSQL() was the exact blind spot that previously allowed a
# real RunRepository LIMIT 1 regression through static CI.
probe = "Db::getInstance()->getValue('SELECT id_run FROM t WHERE source=\'' . pSQL($source) . '\' ORDER BY id_run DESC LIMIT 1', false);"
probe_calls = list(call_bodies(probe, 'getValue'))
if len(probe_calls) != 1 or not re.search(r'\bLIMIT\s+1\b', probe_calls[0][1], re.IGNORECASE):
    raise SystemExit('single-row LIMIT guard parser self-test failed')

violations = []
for php_file in paths:
    if not php_file.is_file():
        continue
    text = php_file.read_text(encoding='utf-8')
    for method in ('getValue', 'getRow'):
        for start, body in call_bodies(text, method):
            if re.search(r'\bLIMIT\s+1\b', body, re.IGNORECASE):
                rel = php_file.relative_to(root)
                line = text.count('\n', 0, start) + 1
                violations.append(
                    f'{rel}:{line}: PrestaShop Db::{method}() appends LIMIT 1; remove manual LIMIT 1'
                )

if violations:
    for violation in violations:
        print(violation, file=sys.stderr)
    raise SystemExit(f'Found {len(violations)} redundant PrestaShop single-row LIMIT clause(s)')

print('PRESTASHOP_SINGLE_ROW_LIMIT_CHECK_OK')
PY
