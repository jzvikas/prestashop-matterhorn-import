# Matterhorn Wholesale Import

Standalone high-throughput Matterhorn Wholesale supplier import module for **PrestaShop 9.1.x** and **PHP 8.4+**, based on the reusable architecture from `jzvikas/prestashop-import-skeleton`.

## Status

The production implementation is now assembled across the supplier adapter and reusable skeleton domains:

- streaming `XMLReader` Matterhorn source with checkpoint resume, source fingerprinting and READ semantic-policy fencing;
- semantic mapper with isolated domain hashes;
- category path mapping, manufacturer resolution, Color/Type features and sanitized descriptions;
- pure READ-time Size descriptors resolved to PrestaShop attributes only during persistence, with shop-scoped Size group policy;
- Size combinations with option reference, stock and validated EAN13;
- guarded `READ -> IMPORT -> UPDATE -> REMOVE` orchestration with bounded resume and REMOVE safety;
- out-of-feed deactivation that zeroes both base and combination stock;
- generic specific-price ownership/synchronization infrastructure, which is a no-op for the current Matterhorn feed because the supplier does not provide specific prices;
- secure persistent image queue/state, lease fencing, cross-run/mapping fencing, HTTP revalidation, SSRF/DNS protections, attachment worker, deduplication, orphan recovery and authoritative reconciliation;
- global new-product queue/worker with interrupted-create recovery and retry/backoff;
- `retry`, `doctor`, `status` and bounded `gc` operational commands;
- shop-scoped Back Office configuration and live run/queue status;
- static contracts, changed-feed domain isolation coverage, real MariaDB schema lifecycle coverage and a Docker PrestaShop 9.1.5 runtime gate.

The real PrestaShop gate now covers module install and command registration, Matterhorn CREATE, manufacturer/category/features, Size combinations, EAN and stock, description persistence, image-manifest enqueue, selective changed-feed UPDATE hashes, REMOVE dry-run, out-of-feed deactivate/stock-zero, multishop isolation/product association recovery, and retention/destructive-uninstall behavior.

**The implementation is not marked release-green yet:** the GitHub Actions free-minute limit is currently exhausted, so the latest static, MariaDB and PrestaShop lifecycle gates have been prepared but have not executed on the latest commits. They must run after the Actions quota resets before PROD release approval.

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
| `images/image_url` | ordered persistent image queue manifest |
| `options/option/@id` | combination reference |
| `option_name` | semantic `matterhorn:size:<value>` descriptor, resolved to PrestaShop `Size` later |
| `STOCK` | combination quantity |
| `ean` | combination EAN13 when valid |
| `avaible_in` | raw supplier metadata only; not stock |

## Streaming model

Matterhorn XML is read with `XMLReader` and `LIBXML_NONET`; only one `<product>` payload is materialized at a time. The adapter supports record checkpoint resume and a source fingerprint. It never downloads images or writes catalog attributes during READ.

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
matterhornimport:new-products:enqueue
matterhornimport:new-products
matterhornimport:retry
matterhornimport:status
matterhornimport:gc
```

For normal operation prefer `matterhornimport:run --shop=<id>` and separate image workers. See `docs/PRODUCTION.md` for the scalable new-product lane, reconciliation and cron examples.

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
