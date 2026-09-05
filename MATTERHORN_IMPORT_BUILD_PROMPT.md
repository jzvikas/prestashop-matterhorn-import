# Matterhorn Wholesale Import — Production Build Prompt

Build a production-grade standalone PrestaShop 9.1.x / PHP 8.4+ module for Matterhorn Wholesale on top of `jzvikas/prestashop-import-skeleton`.

Upstream skeleton repository: `jzvikas/prestashop-import-skeleton`.
Upstream baseline used to start this module: main tree `20c66ee31493add980be184eb003a07538ea6253` (2026-09-05).
Target repository: `jzvikas/prestashop-matterhorn-import`.
Technical module name: `matterhornimport`.
Module class: `MatterhornImport`.
Namespace: `Lp\\MatterhornImport`.
CLI prefix: `matterhornimport:`.
Supplier/source name: `matterhorn`.

## Non-negotiable architecture

Reuse the skeleton's infrastructure rather than creating a second import framework. The final module must contain the generated equivalents of READ -> IMPORT -> UPDATE -> REMOVE orchestration, run/snapshot/mapping repositories, domain hashes, transactions/savepoints, interrupted-create recovery, locks, retry, global new-product queue, separate persistent image queue/workers/reconciliation, GC, doctor/status CLI, Back Office bounded status/config, multishop isolation, REMOVE safety, install/uninstall/upgrades and release tests.

Supplier-specific behavior belongs in Source/Mapper/Matterhorn services and small writer/policy extensions. Do not hard-code Matterhorn rules into orchestration/repository/image core. Do not disable or weaken skeleton security/safety controls.

Every implementation commit must be followed by relevant tests/CI inspection. If CI fails, inspect logs and fix the failure before moving to unrelated work. Never disable tests to obtain green CI.

## Matterhorn feed contract

Root: `<products>`; record: `<product id="...">`.

Product fields:
- stable supplier product identity: `product/@id`;
- name: `<name>`;
- supplier creation metadata: `<creation_date>`;
- manufacturer: `<brand>`;
- hierarchy: `<category_path>`;
- stable category key: `<category id="...">name</category>`;
- features: `<color>`, `<type>`;
- ordered images: `<images><image_url>...</image_url></images>`;
- base price: `<price>`;
- HTML description: `<description>`;
- variants: `<options><option id="...">...</option></options>`.

Option fields:
- stable option/combo reference: `option/@id`;
- Size: `<option_name>`;
- stock: `<STOCK>`;
- supplier metadata only: misspelled `<avaible_in>`;
- combination EAN13: `<ean>`.

## Stable identities

`sourceKey = Matterhorn product id`.
PrestaShop product reference = `MH-<product id>`.
Combination reference = Matterhorn option id.
Never use name, EAN, stock or XML position as primary identity.

## Source adapter

`MatterhornXmlSource` must implement `CheckpointableSourceInterface` and remain streaming/bounded-memory with XMLReader. Preserve `LIBXML_NONET`, record checkpoint resume, source fingerprinting and changed-source detection. Never load the whole feed through DOM/SimpleXML/file_get_contents. Per-product SimpleXML parsing after `XMLReader::readOuterXML()` is acceptable.

The adapter must parse nested `options`, preserve image order and deduplicate identical image URLs. Image downloading must never happen in READ.

## Mapping

`MatterhornProductMapper` maps into the skeleton `ProductData`.

- product reference: `MH-<id>`;
- price: raw supplier base price, no hidden markup;
- product base quantity: 0 when variants are used;
- manufacturer: brand, auto-create/reuse through generic resolver;
- categories: key `matterhorn-category:<category id>` and slash path normalized to `A > B > C`;
- features: stable keys `matterhorn:color` and `matterhorn:type`, supplier-owned/authoritative only within module ownership;
- description: safe sanitized HTML, preserving useful tables/div/strong/br formatting while removing script/iframe/event-handler/unsafe markup;
- images: ordered URL list sent into generic image queue;
- combinations: resolved numeric Size attribute IDs, option id as reference, STOCK as quantity, valid 13-digit EAN as `ean13`, zero price/weight impact unless a real supplier rule exists;
- invalid optional EAN: blank it and make the condition observable; do not kill a large feed solely for optional malformed EAN;
- negative stock: normalize to 0 and make observable;
- `avaible_in`: parse/preserve only as supplier metadata; do not treat as stock or invent delivery semantics.

Products with no options may exist as simple products but quantity remains 0 unless a genuine product-level stock field is introduced by the supplier.

## Size resolution

Use PrestaShop 9 `ProductAttribute`, not the PHP built-in `Attribute`. Resolve/reuse/create a shop-safe `Size` attribute group (configurable display name) and values such as `XS`, `M`, `S/M`, `L/XL`. Cache process-local resolutions. Prevent duplicate values. Never pass raw size strings into generic CombinationNormalizer: generic combination payloads require resolved positive numeric `attribute_ids`.

Default combination selection must be deterministic and must not depend on stock. Sorted supplier option reference is acceptable.

## Domain hash invariants

Mandatory regression coverage:
- stock-only option change -> `combination_stock` changes; combination structure/core/price/category/feature/image remain unchanged;
- price-only -> price domain only;
- images-only -> image domain only;
- brand/description/name -> core domain;
- color/type -> feature domain;
- category id/path -> category domain;
- option EAN/size/reference -> combination structure domain.

Do not update all domains on every feed.

## Description/languages

Matterhorn example data is English. Add configurable source language. CREATE must produce a valid product in all required shop languages, using source value as fallback when necessary. UPDATE must update only the configured supplier language and must not overwrite manually translated other languages.

Implement a small Matterhorn-specific `GranularProductWriterInterface` extension/composition to persist description without copying the whole generic writer. Keep generic category/manufacturer/shop-association logic delegated to skeleton services.

## Categories/manufacturer/features/combinations/images

Reuse skeleton CategoryAutoMapper/CategorySynchronizer, ManufacturerResolver, FeatureSynchronizer, CombinationSynchronizer, ImageQueue/ImageWorker/ImageReconciler and ownership state. Do not write parallel frameworks or destructive custom SQL.

## Out-of-feed

Keep generic `DeactivateOutOfFeedPolicy`: out-of-feed product -> inactive + stock 0. Never physically delete products by default. Preserve READ validity, source-size sanity, dry-run percentage and partial-run safety guards so bad/truncated feeds cannot mass-disable the catalog.

## Special prices

Current feed has no specific-price model. Do not invent discounts. Keep generic specific-price infrastructure available but do not populate `specific_prices` in V1 unless a real Matterhorn field is introduced.

## Required configuration

Shop-scoped where data affects a shop:
- `MATTERHORNIMPORT_SOURCE_FILE`;
- source language id;
- category auto-create;
- feature auto-create;
- Size attribute group name;
- generic REMOVE safety and skeleton policies.

No hard-coded shop IDs, DB prefixes or `ps_` table names.

## Expected commands

Final generated module must expose the skeleton equivalents with prefix `matterhornimport:` including doctor, run, read, import, update, remove/dry-run, images, images:reconcile, new-products:enqueue, new-products, retry, status and gc.

## Recommended operational separation

Document independent cron/process lanes for:
1. normal READ/import/update/remove cycle;
2. global new-product worker;
3. one or more image workers with unique worker names;
4. retry/recovery;
5. GC.

Images must remain separate from product processing and retain lease fencing/retry/backoff/SSRF validation.

## Fixture and tests

Keep deterministic supplier fixtures including products 206161, 34375 and 228723. Test nested options, checkpoint resume, fingerprinting, image order/dedup, category path/key, manufacturer, Color/Type, Size resolution, option ref/stock/EAN, invalid optional EAN, duplicate option id, duplicate semantic Size, sanitized description, product with no options, deterministic default combination and domain-hash isolation.

Add real MariaDB + PrestaShop 9.1.5 lifecycle coverage as infrastructure is restored from the skeleton: install -> READ -> IMPORT -> images -> changed feed -> UPDATE -> missing product -> REMOVE dry-run -> REMOVE. Verify mapping, shop associations, descriptions, prices, manufacturer, categories, features, combinations, EAN, stock, image ownership, selective hashes and out-of-feed deactivate/stock-zero.

## Performance/security constraints

Never load the whole feed into PHP memory. Never download images during READ. Avoid per-item uncached lookups where process-local cache is safe. Keep existing batched snapshot writes, keyset pagination and optimizer/index guards. Preserve image SSRF protections, public DNS validation, DNS pinning, no redirects/credentials/proxy, connected-IP verification, MIME/byte/pixel limits, lease fencing and multishop destructive-delete safeguards. Supplier HTML must not become an XSS bypass.

## Implementation order

1. standalone module identity/bootstrap;
2. Matterhorn streaming XML source;
3. mapper + fixture + domain-hash tests;
4. category/manufacturer/features/Size/combinations/EAN;
5. Matterhorn description writer + source-language policy;
6. restore/generate full skeleton DB schema + installer/upgrades + run/snapshot/mapping repositories;
7. full READ/IMPORT/UPDATE/REMOVE orchestration;
8. image queue/workers/reconciliation;
9. global new-products/retry/locks/GC/doctor/status;
10. bounded BO config/status;
11. MariaDB/PrestaShop 9.1.5 lifecycle and multishop tests;
12. performance/race/resume/security deep scan and documentation synchronization.

## Completion gate

Do not call the module PROD-ready until PHP 8.4 syntax/static checks, Composer validation, Symfony services, generated-module identity, MariaDB schema/upgrades, PrestaShop 9.1.5 install/uninstall, READ/IMPORT/UPDATE/REMOVE lifecycle, image pipeline, retry/recovery, multishop isolation, domain-hash isolation and all CI jobs are green. Record genuine limitations instead of claiming tests passed when they were not executed.
