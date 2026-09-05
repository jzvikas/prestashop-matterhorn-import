#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

python3 - "$ROOT" <<'PY'
import sys
from pathlib import Path
r = Path(sys.argv[1])
p = (r / 'src/DTO/ProductData.php').read_text()
c = (r / 'src/Combination/CombinationAttributeResolver.php').read_text()
cat = (r / 'src/Category/CategoryAutoMapper.php').read_text()

for token in (
    'MAX_IMAGES = 1000',
    'MAX_CATEGORIES = 256',
    'MAX_FEATURES = 1024',
    'MAX_COMBINATIONS = 5000',
    'MAX_COMBINATION_ATTRIBUTES = 64',
    'MAX_SPECIFIC_PRICES = 5000',
    '$this->assertOperationalBounds();',
):
    if token not in p:
        raise SystemExit(f'missing Matterhorn ProductData fan-out guard: {token}')

for token in (
    'MAX_TOTAL_ATTRIBUTE_REFS = 20000',
    '$attributeRefs += count($rawAttributes)',
    'Combination attribute reference count exceeds operational limit',
):
    if token not in c:
        raise SystemExit(f'missing Matterhorn combination total fan-out guard: {token}')

for token in (
    'MAX_PATH_DEPTH = 32',
    'count($parts) > self::MAX_PATH_DEPTH',
    'Category path depth exceeds operational limit',
):
    if token not in cat:
        raise SystemExit(f'missing Matterhorn category depth guard: {token}')
PY

echo 'MATTERHORN_PRODUCT_DOMAIN_FANOUT_BOUNDS_CHECK_OK'
