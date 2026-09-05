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
  [[ -f "$file" ]] || { echo "FAIL: item-transaction contract file missing: $file" >&2; exit 1; }
done

require_literal() {
  local file="$1"
  local literal="$2"
  local contract="$3"
  if ! grep -Fq -- "$literal" "$file"; then
    echo "FAIL: $contract [$file]" >&2
    exit 1
  fi
}

reject_literal() {
  local file="$1"
  local literal="$2"
  local contract="$3"
  if grep -Fq -- "$literal" "$file"; then
    echo "FAIL: $contract [$file]" >&2
    exit 1
  fi
}

require_literal "$guard" "getValue('SELECT @@session.in_transaction', false)" 'transaction guard must inspect live session transaction state'
require_literal "$guard" 'START TRANSACTION' 'transaction guard must restore an externally committed transaction'
require_literal "$guard" "SAVEPOINT ' . \$this->savepoint" 'transaction guard must recreate the caller savepoint'
require_literal "$guard" 'recoveryCount' 'transaction guard must expose external-commit recovery count'

require_literal "$import_stage" 'transactionGuard->arm($db, self::SAVEPOINT)' 'IMPORT must arm the shared transaction guard at its item savepoint'
require_literal "$update_stage" 'transactionGuard->arm($db, self::SAVEPOINT)' 'UPDATE must arm the shared transaction guard at its item savepoint'
require_literal "$import_stage" 'transactionGuard->disarm()' 'IMPORT must disarm transaction guard after item/batch completion'
require_literal "$update_stage" 'transactionGuard->disarm()' 'UPDATE must disarm transaction guard after item/batch completion'

require_literal "$remove_stage" 'transactionGuard->arm($db)' 'REMOVE must arm the shared transaction guard'
require_literal "$remove_stage" 'transactionGuard->recoveryCount()' 'REMOVE must observe external-commit recovery'
require_literal "$remove_stage" 'lockProductOwnership($shopId, $source, $sourceKey, $productId)' 'REMOVE must fence product ownership before mutation'

require_literal "$new_worker" 'transactionGuard->arm($db)' 'new-product worker must arm the shared transaction guard'
require_literal "$new_worker" 'transactionGuard->recoveryCount()' 'new-product worker must observe external-commit recovery'

# Only services that actually call PrestaShop ObjectModel/stock APIs need to restore
# a stage-owned transaction. FeatureSynchronizer deliberately mutates feature_product
# through Db directly; its ObjectModel creation surface lives in FeatureResolver.
for file in "$base_writer" "$matterhorn_writer" "$category" "$manufacturer" "$feature_resolver" "$combination"; do
  require_literal "$file" 'transactionGuard->restoreAfterExternalCommit()' 'PrestaShop-facing writer/resolver must restore stage-owned transactions after ObjectModel/API calls'
done
require_literal "$feature_sync" "$db->delete(" 'FeatureSynchronizer must keep feature replacement on the direct-DB path'
require_literal "$feature_sync" "$db->insert('feature_product'" 'FeatureSynchronizer must keep feature assignment on the direct-DB path'
reject_literal "$feature_sync" 'new \Feature(' 'FeatureSynchronizer must not introduce an unguarded Feature ObjectModel write'
reject_literal "$feature_sync" 'new \FeatureValue(' 'FeatureSynchronizer must not introduce an unguarded FeatureValue ObjectModel write'

echo 'Item transaction guard regression coverage present.'
