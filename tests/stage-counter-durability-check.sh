#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

python3 - "$ROOT" <<'PY'
import sys
from pathlib import Path
r = Path(sys.argv[1])
for file, stage in (('ImportStage.php', 'import'), ('UpdateStage.php', 'update')):
    text = (r / 'src/Import' / file).read_text()
    mapping = text.index('mapping->save(')
    done = text.index(f"runs->increment($runId, '{stage}_done', 1)", mapping)
    release = text.index('RELEASE SAVEPOINT', done)
    if not (mapping < done < release):
        raise SystemExit(f'{stage} done counter must share item durability transaction')

    rollback = text.index('rollbackItemSavepoint($db, $itemError)')
    failed = text.index(f"runs->increment($runId, '{stage}_failed', 1)", rollback)
    errors = text.index('errors->add(', failed)
    if not (rollback < failed < errors):
        raise SystemExit(f'{stage} failed counter must be persisted after item rollback and before error persistence')

    if f"runs->increment($runId, '{stage}_done', $" in text:
        raise SystemExit(f'{stage} still aggregates done counters at batch tail')
PY

echo 'MATTERHORN_STAGE_COUNTER_DURABILITY_CHECK_OK'
