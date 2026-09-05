# Matterhorn Import production operations

This module targets **PrestaShop 9.1.x**, **PHP 8.4+** and MariaDB/MySQL with the critical catalog and module tables on **InnoDB**.

## 1. Configure one shop at a time

Open the Matterhorn Wholesale Import module configuration while a concrete shop is selected. Configure:

- supplier XML path and source language;
- supplier Size attribute-group name;
- category/feature auto-create policies;
- maximum REMOVE percentage guard;
- stage batch size, max-items and time limit;
- image worker batch/runtime;
- global new-product worker batch/runtime;
- retry batch limit.

Settings are shop-scoped. CLI arguments override the Back Office defaults for that invocation. The source language, category/feature auto-create policies and Size group are part of the READ semantic policy hash; if any of them changes while READ is paused, resume is blocked and a fresh run is required.

Before enabling cron, run:

```bash
php bin/console matterhornimport:doctor --shop=1
php bin/console matterhornimport:status --shop=1
```

Do not schedule production writes while `doctor` reports errors. `doctor` also validates the current module schema, the exact exclusive product-ownership index, Size mapping references and the source-scoped image reconciliation queue index.

### Upgrading retained data to 0.1.7

Version `0.1.7` enforces one module source owner per PrestaShop product and shop with the exact unique key `uq_shop_product_owner (id_shop, id_product)`.

The upgrade is deliberately fail-closed. If legacy retained mapping data contains two different source mappings that point to the same `id_shop` + `id_product`, the installer/upgrade does **not** guess which source owns that product and does not delete either mapping automatically. Resolve the conflicting legacy rows manually after identifying the correct owner, then rerun the module upgrade/repair and `matterhornimport:doctor --shop=<id>`.

Do not bypass the conflict guard by manually adding the unique index before reviewing the mappings. A forced index creation can hide the ownership problem by requiring arbitrary row deletion. After a successful upgrade, `doctor` must report the exact unique ownership index before any catalog-mutating cron is enabled.

## 2. Normal import cycle

The simplest production cycle is the bounded all-in-one command:

```bash
php bin/console matterhornimport:run --shop=1 --json
```

It executes/resumes the guarded sequence:

`READ -> IMPORT -> UPDATE -> REMOVE`

A bounded invocation can return `paused`. Resume the same run ID rather than starting a competing run:

```bash
php bin/console matterhornimport:run --shop=1 --run=123 --json
```

The shop/source advisory lock prevents two catalog mutation stages from running concurrently. Catalog entity creation additionally uses shared `lpimp:*` advisory-lock namespaces for manufacturer, category, attribute and feature resolution so different supplier import modules cannot race while creating the same global PrestaShop entity.

### Stage-by-stage mode

For explicit orchestration:

```bash
php bin/console matterhornimport:read --shop=1 --json
php bin/console matterhornimport:import --shop=1 --run=123 --json
php bin/console matterhornimport:update --shop=1 --run=123 --json
php bin/console matterhornimport:remove --shop=1 --run=123 --dry-run
php bin/console matterhornimport:remove --shop=1 --run=123 --json
```

Always preview REMOVE after supplier/feed anomalies. The configured maximum REMOVE percentage is enforced even without `--dry-run`.

## 3. Global new-product lane

For large catalogs, new product creation can be pre-drained through the persistent queue after READ:

```bash
php bin/console matterhornimport:new-products:enqueue --shop=1 --run=123
php bin/console matterhornimport:new-products --shop=1
```

This lane uses the same shop/source lock as IMPORT, so it must not mutate the same shop concurrently with `run`/`import`/`update`/`remove`. After the queue has been drained, still run the normal IMPORT stage for the same run. IMPORT will skip products already mapped by the worker, create any remaining rows, and mark the IMPORT stage complete before UPDATE is allowed.

The queue is restart-safe: leases are fenced, expired leases can be reclaimed, interrupted creates are recovered, retryable database failures use backoff, and a newer run can hand a newer payload to an already-processing source key without losing that generation. If an older generation creates the product first, the requeued newer generation updates that same mapped product rather than creating a duplicate.

## 4. Images

Catalog stages only enqueue image work. Process it independently:

```bash
php bin/console matterhornimport:images --shop=1
```

The downloader blocks private/reserved destinations, validates DNS and the connected endpoint, follows no redirects, validates MIME/dimensions/byte limits, supports HTTP conditional revalidation and deduplicates content. Image URLs above 16 KiB are rejected before URL parsing, DNS resolution or network access.

After a complete catalog run and after **all image jobs for that shop/source** have drained, reconcile the authoritative image manifest. For large shops, use a bounded invocation:

```bash
php bin/console matterhornimport:images:reconcile \
  --shop=1 \
  --run=123 \
  --batch=500 \
  --max-items=5000 \
  --time-limit=300
```

The command persists `image_reconcile_status`, `image_reconcile_checkpoint` and cumulative `image_reconcile_done` on the run. If it returns `paused`, execute the **same command with the same run ID** again; it resumes after the last fully reconciled source key. A crash before a checkpoint deliberately replays that one product, and per-product reconciliation is designed to be idempotent.

Reconciliation is blocked when:

- the run is not the latest run for the shop/source;
- READ/IMPORT/UPDATE/REMOVE are not completed;
- the selected run still has unresolved image jobs;
- any older/newer image job for the same shop/source is unresolved.

An unchanged image manifest may legitimately reuse a live image state from an earlier run; current-run freshness is not required when the state still belongs to the same shop/product and the desired URL exists. HTTP `304 Not Modified` is accepted only when the corresponding live image state is still valid; a stale/missing-state race fails closed and is retried instead of silently accepting an unverifiable cached asset.

### Periodic same-URL content revalidation

A supplier can replace the bytes behind an unchanged image URL. Because the catalog image hash intentionally represents the ordered URL manifest, that change does not cause normal UPDATE to enqueue the image again. Use the bounded stale-state scheduler after the latest run has completed image reconciliation:

```bash
php bin/console matterhornimport:images:revalidate \
  --shop=1 \
  --age-hours=24 \
  --limit=5000

php bin/console matterhornimport:images --shop=1
```

The scheduler does **not** download images itself. It selects only module-owned image states older than the configured age, excludes out-of-feed products and products that already have unresolved image work, reads their desired URLs from the latest completed/reconciled run snapshot, and re-enqueues them through the normal secure image queue. The worker then uses stored `ETag`/`Last-Modified` validators where available; HTTP `304` refreshes state age without rebuilding the image, while changed content follows the normal validated replacement/deduplication path.

The scheduler is bounded by product count and the existing snapshot payload window. If `payload_window_deferred` is greater than zero, simply run the scheduler again later. Already scheduled products are excluded while their image jobs remain unresolved, and successfully revalidated states get a fresh `updated_at`, so repeated invocations naturally advance through a large catalog.

Check image/reconciliation progress with:

```bash
php bin/console matterhornimport:status --shop=1
php bin/console matterhornimport:doctor --shop=1
```

## 5. Retry, status and GC

Inspect state:

```bash
php bin/console matterhornimport:status --shop=1
php bin/console matterhornimport:doctor --shop=1
```

`status` reports persisted supplier normalization warnings separately from true import errors.

Explicitly reset failed retryable jobs only after the underlying cause is understood:

```bash
php bin/console matterhornimport:retry --shop=1 --domain=all --json
```

Retry reset updates are status-fenced at write time. A stale operator/worker candidate list therefore cannot clear a lease that another worker has acquired in the meantime.

Run bounded metadata GC separately:

```bash
php bin/console matterhornimport:gc --shop=1 --keep-run=123 --json
```

GC is row/time bounded and only removes safe module-owned history/queue state; it is not a catalog deletion command. The latest shop/source snapshot is retained because authoritative image reconciliation and stale image revalidation read their desired image manifest directly from snapshot payloads. Once a newer generation exists, older snapshots can become collectible according to the GC retention boundary.

## 6. Recommended cron layout

Example for shop `1` when Back Office runtime limits are configured:

```cron
# Main supplier cycle every 30 minutes. flock prevents shell-level overlap too.
*/30 * * * * cd /var/www/prestashop && flock -n /var/lock/matterhorn-shop-1.lock php bin/console matterhornimport:run --shop=1 --json >> var/log/matterhorn-run.log 2>&1

# Drain image work frequently.
*/5 * * * * cd /var/www/prestashop && php bin/console matterhornimport:images --shop=1 >> var/log/matterhorn-images.log 2>&1

# Once per day, schedule a bounded batch of image states that have not been HTTP-revalidated for 24h.
# This succeeds only after the latest run has completed authoritative image reconciliation.
23 4 * * * cd /var/www/prestashop && php bin/console matterhornimport:images:revalidate --shop=1 --age-hours=24 --limit=5000 >> var/log/matterhorn-image-revalidate.log 2>&1

# Retry reset is deliberately infrequent; inspect failures before enabling automatic retry.
17 * * * * cd /var/www/prestashop && php bin/console matterhornimport:retry --shop=1 --domain=all --json >> var/log/matterhorn-retry.log 2>&1

# Health/status snapshots.
7 * * * * cd /var/www/prestashop && php bin/console matterhornimport:doctor --shop=1 --json >> var/log/matterhorn-doctor.log 2>&1
12 * * * * cd /var/www/prestashop && php bin/console matterhornimport:status --shop=1 >> var/log/matterhorn-status.log 2>&1

# Conservative bounded cleanup once per day; set --keep-run to your retention policy.
43 3 * * * cd /var/www/prestashop && php bin/console matterhornimport:gc --shop=1 --keep-run=0 --json >> var/log/matterhorn-gc.log 2>&1
```

Use separate lock/log files for each shop. Image workers can be scheduled separately because their queue has its own lease/fencing model. Image reconciliation needs the latest completed run ID, so normally invoke it from your orchestration after checking `status`, rather than hard-coding a stale run ID in cron. Repeated bounded reconciliation invocations for the same current run are safe because progress is checkpointed. The stale revalidation scheduler uses the same shop/source import lock while selecting/enqueueing manifests, so if a catalog stage is active it fails closed rather than scheduling against a moving latest-run target.

## 7. Deployment gate

Before declaring a release production-ready, the following must pass on the release commit:

```bash
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bash tests/static-release-check.sh
php tests/database-lifecycle-check.php   # against MariaDB 10.11+
bash tests/prestashop-runtime-check.sh   # Docker; PrestaShop 9.1.5 / PHP 8.4
```

GitHub Actions contains equivalent static, MariaDB lifecycle and real PrestaShop lifecycle jobs. Do not disable a failing gate or mark the module release-green until all current gates execute successfully.

## 8. Uninstall retention

`MATTERHORNIMPORT_RETAIN_DATA_ON_UNINSTALL` defaults to `1`, matching the reusable skeleton safety policy. Normal uninstall therefore removes module configuration but retains module tables. Set the global retention flag to `0` only when a destructive uninstall is explicitly intended; the lifecycle test verifies both modes. A failed reinstall/repair preserves any pre-existing or partially-created Matterhorn table instead of blindly dropping retained module data.