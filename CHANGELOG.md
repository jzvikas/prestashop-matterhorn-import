# Changelog

All notable Matterhorn Import changes are tracked here. A version is not production-approved until the release gates documented in `README.md` and `docs/PRODUCTION.md` have executed successfully on that commit.

## Unreleased

## 0.1.7

- Enforced exclusive PrestaShop product ownership per shop with `uq_shop_product_owner (id_shop, id_product)` so two supplier sources cannot manage the same product silently.
- Added an idempotent retained-data/upgrade migration that fails closed when a legacy database already contains cross-source ownership conflicts instead of choosing an owner automatically.
- Removed ownership-sensitive `ON DUPLICATE KEY UPDATE` behavior from mapping persistence; exact owners update in place while foreign product-owner collisions now fail explicitly.
- Added runtime database safety validation for the exact unique ownership index and MariaDB regression coverage for duplicate-source ownership rejection and legacy conflict detection.
- Added a shared item-transaction recovery guard across IMPORT, UPDATE, REMOVE, the global new-product worker and nested Product/Category/Manufacturer/Feature/Combination ObjectModel paths so third-party hook commits recreate the caller-owned transaction before module durability writes.
- Changed REMOVE to one transaction per product, re-locking exact mapping ownership after an external PrestaShop commit and fencing the final `out_of_feed` write to the same `id_product` with an exact affected-row check.
- Moved new-product queue generation finalization inside the same transaction as mapping/image durability so a crash cannot commit catalog state while leaving the claimed generation unfinalized.
- Recovered every missing active target-shop `product_lang` row independently instead of treating one surviving language row as a complete shop association.
- Fenced image and new-product retry resets with `status='failed'` at UPDATE time so a stale retry candidate list cannot clear a worker lease acquired concurrently, and bounded repository retry batches to 100,000 rows.
- Bypassed PrestaShop `Db` query caching for advisory locks, transaction-state decisions, mutable run/snapshot state, worker lease/queue reads, catalog resolver decisions, error counters, doctor state and Back Office live status reads.
- Kept CLI status, doctor queue/orphan health and Back Office operational counters source-scoped so another source cannot contaminate Matterhorn shop status.
- Serialized manufacturer, category, attribute and feature creation with shared `lpimp:*` advisory-lock namespaces so concurrent supplier modules recheck the same global PrestaShop catalog namespace before creating rows.
- Reused shared features when adding a missing global feature value instead of creating a duplicate same-name feature in the target shop.
- Avoided sticky negative attribute-availability caching so a concurrently associated Size attribute can become visible during the same long-running worker process.
- Fenced category mapping assignment with a fresh post-write row check before process-cache seeding so a concurrent row loss/change cannot become a phantom successful mapping.
- Fenced supplier attribute group/value mapping persistence with a fresh joined identity check before process-cache seeding so concurrent overwrite/loss cannot publish an unverified Size resolution.
- Fenced feature synchronization with fresh target-shop exclusivity checks, pre-mutation state revalidation and exact-value optimistic deletes so concurrent Back Office changes fail closed instead of being overwritten.
- Hardened combination synchronization with fresh ownership/default reads, exact target-product checks and atomic multishop detach guarded by another live shop association.
- Fenced combination adoption and destructive cleanup to the fresh `shop/source/source_key/product/semantic/id_product_attribute` owner identity, failing closed on foreign/stale mappings.
- Preserved unmapped/manual duplicate combinations during supplier cleanup and removed broad combination-mapping delete APIs; authoritative mapping removal now uses exact affected-row fencing.
- Revalidated exclusive combination ownership immediately before global ObjectModel deletion and fail closed on shared or ambiguous association topology.
- Preserved a live manual default combination when authoritative Matterhorn cleanup removes all module-owned survivors by resynchronizing `product_shop.cache_default_attribute` from the remaining target-shop `default_on` row.
- Avoided duplicate combination structure/stock projection work during READ by deriving both hashes in one traversal and caching only the two final hash strings.
- Fenced authoritative specific-price deletion to the exact live rule that was inspected, preserving a concurrent manual edit while relinquishing module ownership.
- Bounded specific-price semantic adoption lookup to the exact SQL identity and at most two IDs instead of materializing every product specific-price row.
- Made multishop image detach atomic with one guarded `DELETE ... INNER JOIN` statement instead of a racy pre-delete shop-count check.
- Revalidated live image ownership immediately before destructive cleanup and made image-state GC recheck current references at delete time.
- Scoped stale image revalidation's unresolved-queue fence to the complete `shop/source/source_key/product` owner identity so stale jobs from another source cannot indefinitely suppress Matterhorn revalidation.
- Corrected image-orphan retry backoff to MySQL/MariaDB left-to-right assignment semantics and bounded orphan GC pages independently of the caller chunk size.
- Rejected image URLs above 16 KiB before `parse_url`, DNS lookup or network access.
- Failed closed when an HTTP `304 Not Modified` response races with stale or missing image-state ownership instead of accepting an unverifiable cached asset.
- Kept supplier normalization warnings observable in snapshot payloads/status without letting warning-only differences dirty catalog domain hashes.
- Removed the UPDATE fallback that routed payload-only metadata changes into unnecessary product core writes.
- Canonicalized supplier warning ordering so semantically identical option reordering does not churn snapshot payload hashes.
- Preserved Matterhorn `avaible_in` and `creation_date` as supplier metadata without assigning stock/delivery/date-add semantics or dirtying catalog domain hashes.
- Added regression coverage for warning/domain isolation, warning-order determinism, supplier metadata isolation, retry lease fencing, item-transaction recovery, exact REMOVE ownership, partial language recovery, live/source-scoped observability, single-pass combination hashing, bounded specific-price lookup, shared resolver locks, category path concurrency, category assignment durability, attribute mapping write verification, feature concurrent changes, exact combination owner cleanup/manual-duplicate preservation, atomic combination/image detach, specific-price optimistic deletion, source-owner image revalidation fencing, image URL bounds, stale-304 handling and ownership schema safety.
- Made the GitHub Actions release workflow manually dispatchable when push-trigger execution is unavailable.

## 0.1.6

- Added bounded `matterhornimport:images:revalidate` scheduling for supplier image-content changes behind unchanged URLs.
- Reuses the secure persistent image worker and HTTP `ETag` / `Last-Modified` conditional requests instead of downloading every image on every import.
- Added `idx_revalidate (id_shop, source, updated_at, source_key)` for stale image-state discovery.
- Bounded stale discovery by product count and the existing snapshot payload window. If `payload_window_deferred` is greater than zero, simply run the scheduler again later. Already scheduled products are excluded while their image jobs remain unresolved, and successfully revalidated states get a fresh `updated_at`, so repeated invocations naturally advance through a large catalog.
- Added latest completed/reconciled-run fencing, out-of-feed exclusion, unresolved-job fencing and missing-manifest fail-closed checks.
- Added `doctor`, static, MariaDB and PrestaShop runtime coverage for the new index/command surface.
- Hardened PrestaShop multishop duplicated `product` / `product_shop` price/active shadow consistency.
- Separated persisted supplier normalization warnings from true import errors in status output.
- Preserved any pre-existing or partially-created Matterhorn schema if reinstall/repair fails.

## 0.1.5

- Added resumable authoritative image reconciliation state to import runs.
- Added reconciliation status, source-key checkpoint and cumulative processed counter.
- Added source-wide image queue fencing and bounded reconciliation resume support.
- Exposed reconciliation progress in Back Office/status/doctor surfaces.

## 0.1.4

- Added `source_policy_hash` to import runs.
- Snapshots source language, category/feature auto-create policy and Size attribute-group semantics for READ.
- Blocks paused READ resume when those semantics change between invocations.
- Connected the shop-scoped configurable Size group to runtime mapping/resolution.

## 0.1.3

- Added high-volume hot-query indexes for latest-run lookup, REMOVE keyset traversal and per-shop image/new-product queue claims.
- Made index creation idempotent for fresh install, retained-data reinstall and upgrade paths.
- Added process caches and write de-duplication for frequently repeated attribute, feature and category resolution.

## 0.1.2

- Added durable image-orphan recovery state for externally committed PrestaShop image-hook failure paths.
- Added retry/backoff indexes for bounded orphan recovery and GC integration.

## 0.1.1

- Added persistent `out_of_feed` mapping state and supporting feed-state index through the mapping-state upgrade path.

## 0.1.0

- Initial standalone Matterhorn Wholesale import module generated from the reusable high-volume PrestaShop import skeleton.
- Supplier XML streaming, semantic mapping, staged READ/IMPORT/UPDATE/REMOVE orchestration, persistent image/new-product queues, category/features/combinations, multishop isolation and operational CLI foundation.
