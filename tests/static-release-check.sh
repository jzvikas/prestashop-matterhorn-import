#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php -r 'if (PHP_VERSION_ID < 80400) { fwrite(STDERR, "PHP 8.4+ required\n"); exit(1); }'
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -path './vendor' -prune -o -name '*.php' -print0)
php tests/matterhorn-parser-mapper-check.php
php tests/attribute-resolution-contract-check.php
php tests/schema-installer-contract-check.php
php tests/product-persistence-contract-check.php
php tests/feature-sync-contract-check.php
php tests/combination-sync-contract-check.php
php tests/read-orchestration-contract-check.php
php tests/import-orchestration-contract-check.php
php tests/update-orchestration-contract-check.php
echo "Static release checks: OK"
