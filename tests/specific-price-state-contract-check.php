<?php
declare(strict_types=1);

function specificPriceStateCheck(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__);
$state = (string) file_get_contents($root . '/src/Repository/SpecificPriceStateRepository.php');
$sync = (string) file_get_contents($root . '/src/SpecificPrice/SpecificPriceSynchronizer.php');

specificPriceStateCheck(!str_contains($state, 'ON DUPLICATE KEY UPDATE'), 'specific-price ownership state must not overwrite semantic owners through broad upsert');
specificPriceStateCheck(str_contains($state, 'Specific-price ownership conflict:'), 'specific-price state must fail closed when semantic ownership points at another product/rule');
specificPriceStateCheck(str_contains($state, 'last_seen_run_id<=') && str_contains($state, 'Refusing stale specific-price ownership state save'), 'specific-price state writes must be monotonic by run generation');
specificPriceStateCheck(str_contains($state, 'private function exactOwner(') && str_contains($state, '), false);'), 'specific-price owner verification must bypass PrestaShop Db query cache');
specificPriceStateCheck(str_contains($state, 'id_product=%d AND semantic_key=') && str_contains($state, 'AND id_specific_price=%d'), 'specific-price state writes must fence exact product, semantic key and specific-price id');
specificPriceStateCheck(str_contains($state, ". \" AND applied_hash='\" . pSQL(\$appliedHash) . \"'\""), 'specific-price ownership delete must fence the previously observed applied hash');
specificPriceStateCheck(str_contains($state, '$db->Affected_Rows() !== 1'), 'specific-price ownership delete must detect concurrent replacement/removal');
specificPriceStateCheck(substr_count($sync, '$this->state->delete(') === 2, 'specific-price synchronizer must use exact state deletion in both stale-live and authoritative cleanup paths');
specificPriceStateCheck(substr_count($sync, "(string) \$ownedRow['applied_hash']") >= 2, 'specific-price synchronizer must carry observed applied hash into state deletion');
specificPriceStateCheck(substr_count($sync, "(int) \$ownedRow['id_specific_price']") >= 1 && str_contains($sync, "\n                \$id,\n                (string) \$ownedRow['applied_hash']"), 'specific-price synchronizer must carry exact specific-price identity into state deletion');

echo "Specific-price state contract checks: OK\n";
