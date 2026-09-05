#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NETWORK="mh-ps-runtime-${RANDOM}-$$"
DB_CONTAINER="mh-ps-db-${RANDOM}-$$"
PS_CONTAINER="mh-ps-app-${RANDOM}-$$"

cleanup() {
  docker rm -f "$PS_CONTAINER" >/dev/null 2>&1 || true
  docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

bootstrap_action() {
  local action="$1"
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
    $kernel->shutdown();
  '
}

docker network create "$NETWORK" >/dev/null
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=prestashop \
  -e MARIADB_USER=prestashop -e MARIADB_PASSWORD=prestashop \
  mariadb:10.11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null

for attempt in $(seq 1 60); do
  docker exec "$DB_CONTAINER" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1 && break
  [[ "$attempt" -eq 60 ]] && { docker logs "$DB_CONTAINER" >&2 || true; exit 2; }
  sleep 2
done

docker run -d --name "$PS_CONTAINER" --network "$NETWORK" \
  -e DB_SERVER="$DB_CONTAINER" -e DB_NAME=prestashop -e DB_USER=prestashop -e DB_PASSWD=prestashop -e DB_PREFIX=ps_ \
  -e PS_INSTALL_AUTO=1 -e PS_DOMAIN=localhost -e PS_ENABLE_SSL=0 -e PS_DEV_MODE=0 \
  -e ADMIN_MAIL=admin@example.test -e 'ADMIN_PASSWD=MatterhornRuntime123!' \
  prestashop/prestashop:9.1.5-8.4 >/dev/null

ready=0
for attempt in $(seq 1 120); do
  if docker exec "$PS_CONTAINER" sh -lc 'test -f app/config/parameters.php' >/dev/null 2>&1; then
    count="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM ps_shop WHERE id_shop=1" 2>/dev/null || printf 0)"
    if [[ "$count" == "1" ]]; then ready=1; break; fi
  fi
  sleep 2
done
[[ "$ready" -eq 1 ]] || { docker logs "$PS_CONTAINER" >&2 || true; echo 'PrestaShop did not become ready' >&2; exit 3; }

# Copy the exact checked-out module under test into PrestaShop.
docker exec "$PS_CONTAINER" rm -rf /var/www/html/modules/matterhornimport
docker exec "$PS_CONTAINER" mkdir -p /var/www/html/modules/matterhornimport
docker cp "$ROOT/." "$PS_CONTAINER:/var/www/html/modules/matterhornimport/"
docker exec "$PS_CONTAINER" chown -R www-data:www-data /var/www/html/modules/matterhornimport

bootstrap_action install

# All module tables must exist after a real PrestaShop install.
table_count="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'" )"
[[ "$table_count" -eq 15 ]] || { echo "Expected 15 module tables, got $table_count" >&2; exit 4; }

# Symfony container must expose the complete command surface.
commands="$(docker exec "$PS_CONTAINER" sh -lc 'cd /var/www/html && APP_ENV=prod APP_DEBUG=0 php -d memory_limit=512M bin/console list matterhornimport --raw')"
for command in \
  matterhornimport:doctor matterhornimport:run matterhornimport:read matterhornimport:import matterhornimport:update matterhornimport:remove \
  matterhornimport:images matterhornimport:images:reconcile matterhornimport:new-products:enqueue matterhornimport:new-products \
  matterhornimport:retry matterhornimport:status matterhornimport:gc; do
  grep -q "^${command}\b" <<<"$commands" || { echo "Missing console command: $command" >&2; exit 5; }
done

# Create a second shop and prove shop-scoped operational configuration plus association recovery.
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
  echo "MULTISHOP_OK shop2={$shop2Id} product={$idProduct}\n";
'

# Default uninstall policy retains module data but must remove configuration.
bootstrap_action uninstall
retained="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'")"
[[ "$retained" -eq 15 ]] || { echo 'Default uninstall did not retain module tables' >&2; exit 6; }
config_left="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'MATTERHORNIMPORT_%'")"
[[ "$config_left" -eq 0 ]] || { echo "Uninstall left $config_left Matterhorn configuration rows" >&2; exit 7; }

# Reinstall over retained tables must work; explicit retain=0 must then drop all module tables.
bootstrap_action install
docker exec "$PS_CONTAINER" php -r 'chdir("/var/www/html"); require "config/config.inc.php"; if (!Configuration::updateValue("MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL", "0", false, 0, 0)) { exit(8); }'
bootstrap_action uninstall
remaining="$(docker exec "$DB_CONTAINER" mariadb -N -uroot -proot prestashop -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='prestashop' AND table_name LIKE 'ps_li_matterhornim_99dfbf_%'")"
[[ "$remaining" -eq 0 ]] || { echo "Destructive uninstall left $remaining module tables" >&2; exit 9; }

echo 'Matterhorn PrestaShop 9.1.5 lifecycle: OK'
