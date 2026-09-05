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

for php_file in paths:
    if not php_file.is_file():
        continue
    text = php_file.read_text(encoding='utf-8')
    for method in ('getValue', 'getRow'):
        for match in re.finditer(rf'{method}\((.*?)\)\s*;?', text, re.IGNORECASE | re.DOTALL):
            if re.search(r'LIMIT\s+1\b', match.group(1), re.IGNORECASE):
                rel = php_file.relative_to(root)
                raise SystemExit(
                    f'PrestaShop Db::{method}() appends LIMIT 1; remove manual LIMIT 1 from {rel}'
                )

print('PRESTASHOP_SINGLE_ROW_LIMIT_CHECK_OK')
PY
