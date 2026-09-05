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

violations = []
for php_file in paths:
    if not php_file.is_file():
        continue
    text = php_file.read_text(encoding='utf-8')
    for method in ('getValue', 'getRow'):
        for match in re.finditer(rf'{method}\((.*?)\)\s*;?', text, re.IGNORECASE | re.DOTALL):
            if re.search(r'LIMIT\s+1\b', match.group(1), re.IGNORECASE):
                rel = php_file.relative_to(root)
                line = text.count('\n', 0, match.start()) + 1
                violations.append(
                    f'{rel}:{line}: PrestaShop Db::{method}() appends LIMIT 1; remove manual LIMIT 1'
                )

if violations:
    for violation in violations:
        print(violation, file=sys.stderr)
    raise SystemExit(f'Found {len(violations)} redundant PrestaShop single-row LIMIT clause(s)')

print('PRESTASHOP_SINGLE_ROW_LIMIT_CHECK_OK')
PY
