# Matterhorn Wholesale Import

Standalone high-throughput Matterhorn Wholesale supplier import module for **PrestaShop 9.1.x** and **PHP 8.4+**, based on the reusable architecture from `jzvikas/prestashop-import-skeleton`.

## Status

The production implementation is now assembled across the supplier adapter and reusable skeleton domains:

- streaming `XMLReader` Matterhorn source with checkpoint resume, source fingerprinting and READ semantic-policy fencing;
- semantic mapper with isolated domain hashes and single-pass combination structure/stock hashing to avoid duplicate READ projection work;
- category path mapping, manufacturer resolution, Color/Type features and sanitized descriptions;
- explicit Back Office category synchronization/mapping with exact-path auto-map and an intentional create-and-map action; normal product import never creates categories implicitly;
- pure READ-time Size descriptors resolved to PrestaShop attributes only during persistence, with shop-scoped configurable Size group policy;
- Size combinations with option reference, stock and validated EAN13;
- guarded `READ -> IMPORT -> UPDATE -> REMOVE` orchestration with bounded resume and REMOVE safety;
- shared item-transaction recovery across IMPORT, UPDATE, REMOVE, the global new-product worker and nested PrestaShop ObjectModel paths so hook-triggered commits cannot silently dissolve module durability boundaries;
- one-transaction-per-product REMOVE with exact mapping ownership re-lock/fencing after an external commit;
- exclusive per-shop product ownership enforced by `uq_shop_product_owner (id_shop, id_product)` with a fail-closed retained-data upgrade path;
- out-of-feed deactivation that zeroes both base and combination stock;
- generic specific-price ownership/synchronization infrastructure with bounded semantic lookup; it remains a no-op for the current Matterhorn feed because the supplier does not provide specific prices;
- secure persistent image queue/state, lease fencing, cross-run/mapping fencing, HTTP revalidation, SSRF/DNS protections, attachment worker, deduplication, orphan recovery and resumable authoritative reconciliation;
- bounded image-queue persistence with 16 KiB URL admission, 7 MiB escaped SQL write batches and token-wide lease heartbeat so slow downloads do not expire untouched siblings from the same claimed batch;
- bounded age-based image revalidation that catches supplier image-content changes even when the URL itself does not change, using conditional HTTP through the existing image worker;
- global new-product queue/worker with interrupted-create recovery, generation fencing, retry/backoff and transactional generation finalization with mapping/image durability;
- queue-aware new-product enqueue with an 8 MiB payload preload window, 7 MiB escaped SQL write batches, `--max-items` / `--time-limit` execution budgets and token-wide sibling lease heartbeat;
- fail-closed diagnostics for completed new-product rows whose persisted product ownership no longer matches the exact shop/source/source-key mapping;
- multishop hardening for global category/feature/combination ownership, partial `product_lang` recovery and PrestaShop duplicated `product`/`product_shop` shadow fields;
- shared cross-import advisory locking for manufacturer/category/attribute/feature creation plus live-state revalidation before destructive feature/combination/specific-price/image operations;
- authoritative combination cleanup that preserves a remaining manual target-shop default instead of leaving stale `cache_default_attribute` state;
- supplier image URL normalization that skips non-HTTP or over-16-KiB optional image URLs as observable warnings instead of failing an otherwise valid catalog product;
- `retry`, `doctor`, `status` and bounded `gc` operational commands with live, source-scoped queue/orphan/error observability;
- shop-scoped Back Office configuration and live source-consistent run/queue status;
- static contracts, changed-feed domain isolation coverage, real MariaDB schema lifecycle coverage and a Docker PrestaShop 9.1.5 runtime gate.

The real PrestaShop gate covers module install and command registration, Matterhorn CREATE, manufacturer/category/features, Size combinations, EAN and stock, description persistence, image-manifest enqueue, selective changed-feed UPDATE hashes, REMOVE dry-run, out-of-feed deactivate/stock-zero, multishop isolation/product association recovery, and retention/destructive-uninstall behavior.

**Release status is commit-specific:** production approval requires the exact release commit to pass the PHP 8.4 static contracts, MariaDB lifecycle and PrestaShop 9.1.5 runtime lifecycle. A previously green hardening commit does not automatically make a later commit green.

Primary build specification: [`MATTERHORN_IMPORT_BUILD_PROMPT.md`](MATTERHORN_IMPORT_BUILD_PROMPT.md). Production/cron operations: [`docs/PRODUCTION.md`](docs/PRODUCTION.md).

## Supplier mapping

| Matterhorn XML | Module mapping |
| --- | --- |
| `product/@id` | source key; product reference `MH-<id>` |
| `name` | product name |
| `price` | base product price |
| `brand` | manufacturer |
| `category/@id` | stable key `matterhorn-category:<id>` |
| `category_path` | hierarchical category path |
| `color` | `Color` feature |
| `type` | `Type` feature |
| `description` | sanitized product description HTML |
| `images/image_url` | ordered persistent image queue manifest; invalid optional URLs are skipped with warnings |
| `options/option/@id` | combination reference |
| `option_name` | semantic `matterhorn:size:<value>` descriptor, resolved to the configured PrestaShop Size group later |
| `STOCK` | combination quantity |
| `ean` | combination EAN13 when valid |
| `avaible_in` | raw supplier metadata only; not stock |

## Streaming model

Matterhorn XML is read with `XMLReader` and `LIBXML_NONET`; only one `<product>` payload is materialized at a time. The adapter supports record checkpoint resume and a source fingerprint. The source language, feature auto-create policy and Size group are snapshotted into the run policy hash, so a paused READ cannot resume after those semantics change. Category mapping is managed separately through the Back Office mapping page and is not a READ auto-create policy. READ never downloads images or writes catalog attributes.

Supplier normalization warnings remain part of the snapshot payload and operational observability but do not dirty catalog domain hashes. This includes malformed optional EAN13, negative stock normalization and rejected optional image URLs.

## CLI

The module exposes the complete command surface under `matterhornimport:`:

```text
matterhornimport:doctor
matterhornimport:run
matterhornimport:read
matterhornimport:import
matterhornimport:update
matterhornimport:remove
matterhornimport:images
matterhornimport:images:reconcile
matterhornimport:images:revalidate
matterhornimport:new-products:enqueue
matterhornimport:new-products
matterhornimport:retry
matterhornimport:status
matterhornimport:gc
```

For normal operation prefer `matterhornimport:run --shop=<id>` and separate image workers. After the latest image manifest is reconciled, use bounded periodic `matterhornimport:images:revalidate` scheduling to detect same-URL supplier image changes without rechecking every image on every import. For large new-product snapshots, `matterhornimport:new-products:enqueue` is queue-aware and bounded by both row and time budgets. See `docs/PRODUCTION.md` for the scalable lanes, upgrade procedures and cron examples.

## Release checks

Static release checks:

```bash
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bash tests/static-release-check.sh
```

Real database lifecycle, against MariaDB 10.11+:

```bash
LP_DB_HOST=127.0.0.1 LP_DB_USER=root LP_DB_PASSWORD=root LP_DB_NAME=matterhorn_test php tests/database-lifecycle-check.php
```

Real PrestaShop lifecycle, using Docker:

```bash
bash tests/prestashop-runtime-check.sh
```

GitHub Actions defines all three gates: PHP 8.4 static contracts, MariaDB schema lifecycle, and PrestaShop **9.1.5 / PHP 8.4** full catalog + multishop + install/uninstall lifecycle.

## Production rule

Do not treat a release commit as production-approved until all current CI gates run and are green. Tests must not be disabled or bypassed to obtain a green release.
