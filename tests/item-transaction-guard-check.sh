#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

guard="src/Util/ItemTransactionGuard.php"
import_stage="src/Import/ImportStage.php"
update_stage="src/Import/UpdateStage.php"
remove_stage="src/Import/RemoveStage.php"
new_worker="src/NewProduct/NewProductWorker.php"
base_writer="src/Product/PrestaProductWriter.php"
matterhorn_writer="src/Product/MatterhornProductWriter.php"
category="src/Category/CategoryAutoMapper.php"
manufacturer="src/Manufacturer/ManufacturerResolver.php"
feature_resolver="src/Feature/FeatureResolver.php"
feature_sync="src/Feature/FeatureSynchronizer.php"
combination="src/Combination/CombinationSynchronizer.php"

for file in "$guard" "$import_stage" "$update_stage" "$remove_stage" "$new_worker" "$base_writer" "$matterhorn_writer" "$category" "$manufacturer" "$feature_resolver" "$feature_sync" "$combination"; do
  [[ -f "$file" ]] || { echo "FAIL: missing $file" >&2; exit 1; }
done

require_literal() {
  local file="$1"
  local literal="$2"
  local label="$3"
  if ! grep -Fq -- "$literal" "$file"; then
    echo "FAIL: $label ($file)" >&2
    exit 1
  fi
}

reject_literal() {
  local file="$1"
  local literal="$2"
  local label="$3"
  if grep -Fq -- "$literal" "$file"; then
    echo "FAIL: $label ($file)" >&2
    exit 1
  fi
}

require_literal "$guard" "getValue('SELECT @@session.in_transaction', false)" 'guard transaction-state read must bypass Db query cache'
require_literal "$guard" 'START TRANSACTION' 'guard must restore an externally committed transaction'
require_literal "$guard" "SAVEPOINT ' . \$this->savepoint" 'guard must restore the caller savepoint'
require_literal "$guard" 'recoveryCount' 'guard must expose recovery count'

require_literal "$import_stage" 'transactionGuard->arm($db, self::SAVEPOINT)' 'IMPORT must arm item savepoint recovery'
require_literal "$update_stage" 'transactionGuard->arm($db, self::SAVEPOINT)' 'UPDATE must arm item savepoint recovery'
require_literal "$import_stage" 'transactionGuard->disarm()' 'IMPORT must disarm transaction recovery'
require_literal "$update_stage" 'transactionGuard->disarm()' 'UPDATE must disarm transaction recovery'

require_literal "$remove_stage" 'transactionGuard->arm($db)' 'REMOVE must arm per-product transaction recovery'
require_literal "$remove_stage" 'transactionGuard->recoveryCount()' 'REMOVE must observe hook commit recovery'
require_literal "$remove_stage" 'lockProductOwnership($shopId, $source, $sourceKey, $productId)' 'REMOVE must relock exact product ownership'

require_literal "$new_worker" 'transactionGuard->arm($db)' 'new-product worker must arm transaction recovery'
require_literal "$new_worker" 'transactionGuard->recoveryCount()' 'new-product worker must expose hook commit recovery'

# These services directly invoke PrestaShop ObjectModel/API paths that can run hooks and commit
# the shared connection; each must restore the caller-owned item transaction immediately after.
for file in "$base_writer" "$matterhorn_writer" "$category" "$manufacturer" "$feature_resolver" "$combination"; do
  require_literal "$file" 'transactionGuard->restoreAfterExternalCommit()' 'nested ObjectModel path must restore transaction after hook commit'
done

# FeatureSynchronizer itself uses direct DB writes. ObjectModel creation is delegated to
# FeatureResolver, which is guarded above; do not add a redundant recovery probe to the hot sync path.
require_literal "$feature_sync" 'resolver->resolveOrCreate' 'feature synchronizer must delegate feature ObjectModel creation to guarded resolver'
reject_literal "$feature_sync" 'new \\Feature(' 'feature synchronizer must not bypass guarded feature resolver'
reject_literal "$feature_sync" 'new \\FeatureValue(' 'feature synchronizer must not bypass guarded feature-value resolver'

echo 'Item transaction guard regression coverage present.'
