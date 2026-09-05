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
  [[ -f "$file" ]] || { echo "missing $file" >&2; exit 1; }
done

grep -Fq "getValue('SELECT @@session.in_transaction', false)" "$guard"
grep -Fq "START TRANSACTION" "$guard"
grep -Fq "SAVEPOINT ' . \$this->savepoint" "$guard"
grep -Fq 'recoveryCount' "$guard"

grep -Fq 'transactionGuard->arm($db, self::SAVEPOINT)' "$import_stage"
grep -Fq 'transactionGuard->arm($db, self::SAVEPOINT)' "$update_stage"
grep -Fq 'transactionGuard->disarm()' "$import_stage"
grep -Fq 'transactionGuard->disarm()' "$update_stage"

grep -Fq 'transactionGuard->arm($db)' "$remove_stage"
grep -Fq 'transactionGuard->recoveryCount()' "$remove_stage"
grep -Fq 'lockProductOwnership($shopId, $source, $sourceKey, $productId)' "$remove_stage"

grep -Fq 'transactionGuard->arm($db)' "$new_worker"
grep -Fq 'transactionGuard->recoveryCount()' "$new_worker"

for file in "$base_writer" "$matterhorn_writer" "$category" "$manufacturer" "$feature_resolver" "$feature_sync" "$combination"; do
  grep -Fq 'transactionGuard->restoreAfterExternalCommit()' "$file"
done

echo 'Item transaction guard regression coverage present.'
