#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$ROOT/src/Product/ProductShopAssociationManager.php"

python3 - "$FILE" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1]).read_text()
required = (
    'ensureLanguageRows(',
    'missingLanguageIds(',
    '\\Language::getLanguages(false, $shopId)',
    'copyLanguageRow(',
    "'id_lang' => (string) $targetLangId",
    'has no active languages',
    'missing language ids',
    'SELECT id_shop,id_lang FROM `',
)
for token in required:
    if token not in p:
        raise SystemExit(f'missing partial product_lang recovery guard: {token}')

ensure = p.index('$this->ensureLanguageRows($productId, $shopId);')
verify = p.index('$missing = $this->missingLanguageIds($productId, $shopId);', ensure)
if ensure >= verify:
    raise SystemExit('language recovery must happen before final language-set verification')

old = "Could not restore product #' . $productId . ' language association to shop #"
if old in p:
    raise SystemExit('legacy any-product_lang-row verification still present')
PY

echo 'PRODUCT_SHOP_PARTIAL_LANGUAGE_RECOVERY_CHECK_OK'