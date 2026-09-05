#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NETWORK="mh-ps-runtime-${RANDOM}-$$"
DB_CONTAINER="mh-ps-db-${RANDOM}-$$"
PS_CONTAINER="mh-ps-app-${RANDOM}-$$"
STAGE="bootstrap"

cleanup() {
  local rc=$?
  trap - EXIT
  if [[ "$rc" -ne 0 ]]; then
    echo "MATTERHORN_RUNTIME_FAILURE stage=${STAGE} exit=${rc}" >&2
    docker ps -a --filter "name=${DB_CONTAINER}" --filter "name=${PS_CONTAINER}" >&2 || true
    echo '--- PrestaShop container log tail ---' >&2
    docker logs --tail 120 "$PS_CONTAINER" >&2 2>/dev/null || true
    echo '--- MariaDB container log tail ---' >&2
    docker logs --tail 80 "$DB_CONTAINER" >&2 2>/dev/null || true
  fi
  docker rm -f "$PS_CONTAINER" >/dev/null 2>&1 || true
  docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  exit "$rc"
}
trap cleanup EXIT

stage() {
  STAGE="$1"
  echo "MATTERHORN_RUNTIME_STAGE ${STAGE}"
}

bootstrap_action() {
  local action="$1"
  stage "module_${action}"
  docker exec -e MH_ACTION="$action" "$PS_CONTAINER" php -d memory_limit=512M -r '
    chdir("/var/www/html");
    require "config/config.inc.php";
    require_once "app/AdminKernel.php";
    global $kernel;
    $kernel = new AdminKernel("prod", false);
    $kernel->boot();
    Shop::setContext(Shop::CONTEXT_SHOP, 1);
    $context = Context::getContext();
    $context->shop = new Shop(1);
    $languageId = (int) Db::getInstance()->getValue("SELECT id_lang FROM `" . _DB_PREFIX_ . "lang` WHERE active=1 ORDER BY id_lang");
    $context->language = new Language($languageId);
    $module = Module::getInstanceByName("matterhornimport");
    if (!$module) { throw new RuntimeException("Matterhorn module instance not found"); }
    $action = (string) getenv("MH_ACTION");
    $ok = $action === "install" ? $module->install() : ($action === "uninstall" ? $module->uninstall() : false);
    if (!$ok) { throw new RuntimeException("Module action failed: " . $action); }
    // Module install/uninstall may invalidate the compiled prod container. Do not
    // explicitly shut down that stale kernel: this short-lived process exits now,
    // while the next command boots a fresh kernel/cache generation.
  '
}

# The CI workflow prepares the production Composer runtime once. Keep this script
# usable standalone without repeating that work when vendor/autoload.php is present.
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  stage composer_autoload
  (
    cd "$ROOT"
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
  )
fi
[[ -f "$ROOT/vendor/autoload.php" ]] || { echo 'Composer production autoload was not generated' >&2; exit 2; }

stage docker_network
docker network create "$NETWORK" >/dev/null

stage mariadb_start
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=prestashop \
  -e MARIADB_USER=prestashop -e MARIADB_PASSWORD=prestashop \
  mariadb:10.11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null

stage mariadb_ready
for attempt in $(seq 1 60); do
  docker exec "$DB_CONTAINER" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1 && break
  [[ "$attempt" -eq 60 ]] && exit 2
  sleep 2
done

stage prestashop_start
docker run -d --name "$PS_CONTAINER" --network "$NETWORK" \
  -e DB_SERVER="$DB_CONTAINER" -e DB_NAME=prestashop -e DB_USER=prestashop -e DB_PASSWD=prestashop -e DB_PREFIX=ps_ \
  -e PS_INSTALL_AUTO=1 -e PS_DOMAIN=localhost -e PS_ENABLE_SSL=0 -e PS_DEV_MODE=0 \
  -e ADMIN_MAIL=admin@example.test -e 'ADMIN_PASSWD=MatterhornRuntime123!' \
  prestashop/prestashop:9.1.5-8.4 >/dev/null

# parameters.php and ps_shop can appear before the image entrypoint has fully
# finished the installation. Require the final Apache listener as well so the
# module/bootstrap process does not contend with a still-running installer.
stage prestashop_ready
ready=0
for attempt in $(seq 1 120); do
  running="$(docker inspect -f '{{.State.Running}}' "$PS_CONTAINER" 2>/dev/null || true)"
  if [[ "$running" != "true" ]]; then
    echo 'PrestaShop container exited during automatic installation' >&2
    docker logs "$PS_CONTAINER" >&2 || true
    exit 3
  fi
  if docker exec "$PS_CONTAINER" sh -lc 'test -f app/config/parameters.php' >/dev/null 2>&1 \
    && docker exec "$PS_CONTAINER" php -r '$socket=@fsockopen("127.0.0.1",80,$errno,$errstr,1); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);' >/dev/null 2>&1; then
    count="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM ps_shop WHERE id_shop=1" 2>/dev/null || printf 0)"
    if [[ "$count" == "1" ]]; then ready=1; break; fi
  fi
  sleep 2
done
[[ "$ready" -eq 1 ]] || { echo 'PrestaShop did not become ready' >&2; exit 3; }
running="$(docker inspect -f '{{.State.Running}}' "$PS_CONTAINER" 2>/dev/null || true)"
[[ "$running" == "true" ]] || { echo 'PrestaShop container stopped after readiness detection' >&2; docker logs "$PS_CONTAINER" >&2 || true; exit 3; }

stage module_copy
docker exec "$PS_CONTAINER" rm -rf /var/www/html/modules/matterhornimport
docker exec "$PS_CONTAINER" mkdir -p /var/www/html/modules/matterhornimport
docker cp "$ROOT/." "$PS_CONTAINER:/var/www/html/modules/matterhornimport/"
docker exec "$PS_CONTAINER" chown -R www-data:www-data /var/www/html/modules/matterhornimport

bootstrap_action install

stage schema_assertions
table_count="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'" )"
[[ "$table_count" -eq 16 ]] || { echo "Expected 16 module tables, got $table_count" >&2; exit 4; }
revalidate_index="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='prestashop' AND table_name='ps_li_matterhornim_99dfbf_image_state' AND index_name='idx_revalidate'" )"
[[ "$revalidate_index" -eq 4 ]] || { echo "Expected 4 idx_revalidate columns, got $revalidate_index" >&2; exit 4; }

stage command_registration
commands="$(docker exec "$PS_CONTAINER" sh -lc 'cd /var/www/html && APP_ENV=prod APP_DEBUG=0 php -d memory_limit=512M bin/console list matterhornimport --raw')"
for command in \
  matterhornimport:doctor matterhornimport:run matterhornimport:read matterhornimport:import matterhornimport:update matterhornimport:remove \
  matterhornimport:images matterhornimport:images:reconcile matterhornimport:images:revalidate matterhornimport:new-products:enqueue matterhornimport:new-products \
  matterhornimport:retry matterhornimport:status matterhornimport:gc; do
  grep -q "^${command}\b" <<<"$commands" || { echo "Missing console command: $command" >&2; exit 5; }
done

stage domain_lifecycle_start
docker exec "$PS_CONTAINER" php -d memory_limit=512M /var/www/html/modules/matterhornimport/tests/prestashop-domain-runtime.php
stage domain_lifecycle_done

stage multishop_lifecycle
docker exec "$PS_CONTAINER" php -d memory_limit=512M -r '
  chdir("/var/www/html");
  require "config/config.inc.php";
  require_once "modules/matterhornimport/autoload.php";
  $db = Db::getInstance();
  $shop1 = new Shop(1);
  if (!Validate::isLoadedObject($shop1)) { throw new RuntimeException("Shop #1 missing"); }
  $shop2 = new Shop();
  $shop2->active = true;
  $shop2->id_shop_group = (int) $shop1->id_shop_group;
  $shop2->id_category = (int) $shop1->id_category;
  $shop2->theme_name = (string) $shop1->theme_name;
  $shop2->name = "Matterhorn Runtime Shop 2";
  $shop2->color = "#335577";
  if (!$shop2->add()) { throw new RuntimeException("Could not create shop #2"); }
  $shop2Id = (int) $shop2->id;
  $url = new ShopUrl();
  $url->id_shop = $shop2Id; $url->active = true; $url->main = true;
  $url->domain = "localhost"; $url->domain_ssl = "localhost"; $url->physical_uri = "/mh-shop-2/"; $url->virtual_uri = "";
  if (!$url->add()) { throw new RuntimeException("Could not create shop #2 URL"); }

  if (!$db->execute(
    "INSERT IGNORE INTO `" . _DB_PREFIX_ . "lang_shop` (`id_lang`,`id_shop`) " .
    "SELECT l.id_lang," . $shop2Id . " FROM `" . _DB_PREFIX_ . "lang` l WHERE l.active=1"
  )) { throw new RuntimeException("Could not initialize shop #2 languages"); }
  Language::resetStaticCache();

  Shop::setContext(Shop::CONTEXT_ALL);
  if (!Configuration::updateValue("PS_MULTISHOP_FEATURE_ACTIVE", 1)) {
    throw new RuntimeException("Could not enable PrestaShop multishop feature");
  }
  Shop::setContext(Shop::CONTEXT_SHOP, 1);
  $context = Context::getContext();
  $context->shop = $shop1;
  $featureProperty = new ReflectionProperty(Shop::class, "feature_active");
  $featureProperty->setAccessible(true);
  $featureProperty->setValue(null, null);
  if (!Shop::isFeatureActive()) { throw new RuntimeException("PrestaShop multishop feature did not become active"); }

  $group = (int) $shop1->id_shop_group;
  Configuration::updateValue("MATTERHORNIMPORT_BATCH_SIZE", "111", false, $group, 1);
  Configuration::updateValue("MATTERHORNIMPORT_BATCH_SIZE", "222", false, $group, $shop2Id);
  if ((string) Configuration::get("MATTERHORNIMPORT_BATCH_SIZE", null, $group, 1) !== "111") { throw new RuntimeException("Shop #1 config leaked"); }
  if ((string) Configuration::get("MATTERHORNIMPORT_BATCH_SIZE", null, $group, $shop2Id) !== "222") { throw new RuntimeException("Shop #2 config leaked"); }

  Shop::setContext(Shop::CONTEXT_SHOP, 1);
  $context = Context::getContext();
  $context->shop = $shop1;
  $languageId = (int) $db->getValue("SELECT id_lang FROM `" . _DB_PREFIX_ . "lang` WHERE active=1 ORDER BY id_lang");
  $context->language = new Language($languageId);
  $product = new Product();
  $product->active = true; $product->available_for_order = true; $product->show_price = true; $product->price = 10.0;
  $product->reference = "MH-RUNTIME-" . substr(hash("sha256", microtime(true)), 0, 12);
  $product->id_category_default = (int) $shop1->id_category;
  foreach (Language::getLanguages(false) as $language) {
    $idLang = (int) $language["id_lang"];
    $product->name[$idLang] = "Matterhorn runtime product";
    $product->link_rewrite[$idLang] = "matterhorn-runtime-product";
  }
  if (!$product->add()) { throw new RuntimeException("Could not create runtime product"); }
  $idProduct = (int) $product->id;
  $db->delete("product_lang", "id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  $db->delete("product_shop", "id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  $manager = new Lp\MatterhornImport\Product\ProductShopAssociationManager();
  $manager->ensure($idProduct, $shop2Id);
  if (!$manager->hasAssociation($idProduct, $shop2Id)) { throw new RuntimeException("Product shop recovery failed"); }
  $sourceActive = (int) $db->getValue("SELECT active FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product=" . $idProduct . " AND id_shop=1");
  $targetActive = $sourceActive === 1 ? 0 : 1;
  $db->update("product_shop", ["active" => $targetActive], "id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  $manager->ensure($idProduct, $shop2Id);
  $after = (int) $db->getValue("SELECT active FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  if ($after !== $targetActive) { throw new RuntimeException("ensure overwrote existing target-shop state"); }

  $db->update("product_shop", ["price" => 11.25], "id_product=" . $idProduct . " AND id_shop=1");
  $db->update("product_shop", ["price" => 22.50], "id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  $db->update("product", ["price" => 22.50], "id_product=" . $idProduct);
  $manager->restoreDefaultShopShadows($idProduct, $shop2Id, ["price"]);
  $globalPrice = (float) $db->getValue("SELECT price FROM `" . _DB_PREFIX_ . "product` WHERE id_product=" . $idProduct);
  $shop2Price = (float) $db->getValue("SELECT price FROM `" . _DB_PREFIX_ . "product_shop` WHERE id_product=" . $idProduct . " AND id_shop=" . $shop2Id);
  if (abs($globalPrice - 11.25) > 0.000001) { throw new RuntimeException("Default-shop global price shadow repair failed"); }
  if (abs($shop2Price - 22.50) > 0.000001) { throw new RuntimeException("Shadow repair changed target-shop price"); }
  echo "MULTISHOP_OK shop2={$shop2Id} product={$idProduct}\n";
'

bootstrap_action uninstall
stage retained_uninstall_assertions
retained="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'")"
[[ "$retained" -eq 16 ]] || { echo 'Default uninstall did not retain all 16 module tables' >&2; exit 6; }
config_left="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'MATTERHORNIMPORT_%'")"
[[ "$config_left" -eq 0 ]] || { echo "Uninstall left $config_left Matterhorn configuration rows" >&2; exit 7; }

bootstrap_action install
stage destructive_uninstall_policy
docker exec "$PS_CONTAINER" php -r 'chdir("/var/www/html"); require "config/config.inc.php"; if (!Configuration::updateValue("MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL", "0", false, 0, 0)) { exit(8); }'
bootstrap_action uninstall
stage destructive_uninstall_assertions
remaining="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'")"
[[ "$remaining" -eq 0 ]] || { echo "Destructive uninstall left $remaining module tables" >&2; exit 9; }

stage complete
echo 'Matterhorn PrestaShop 9.1.5 lifecycle: OK'