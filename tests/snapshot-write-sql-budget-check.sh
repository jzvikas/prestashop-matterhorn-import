#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$ROOT/src/Repository/SnapshotRepository.php"

python3 - "$FILE" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1]).read_text()
for token in (
    'MAX_WRITE_SQL_BYTES = 8388608',
    '$valuesBudget = self::MAX_WRITE_SQL_BYTES - strlen($prefix) - strlen($suffix)',
    '$row = $this->valueRow($runId, $product)',
    '$rowBytes = strlen($row)',
    '$this->executeValueChunk($prefix, $suffix, $values)',
    "pSQL($product->toJson(), true)",
    'strlen($sql) > self::MAX_WRITE_SQL_BYTES',
    'Escaped Matterhorn snapshot row exceeds SQL write budget',
):
    if token not in p:
        raise SystemExit(f'missing escaped Matterhorn snapshot write budget guard: {token}')

budget = p.index('$valuesBudget = self::MAX_WRITE_SQL_BYTES')
row = p.index('$row = $this->valueRow($runId, $product)', budget)
flush = p.index('$this->executeValueChunk($prefix, $suffix, $values)', row)
if not budget < row < flush:
    raise SystemExit('snapshot write budget must be computed before escaped rows are chunked')

value_row = p[p.index('private function valueRow('):p.index('private function executeValueChunk(')]
if "pSQL($product->toJson(), true)" not in value_row:
    raise SystemExit('snapshot row size must be measured after payload SQL escaping')
PY

echo 'MATTERHORN_SNAPSHOT_WRITE_SQL_BUDGET_CHECK_OK'
