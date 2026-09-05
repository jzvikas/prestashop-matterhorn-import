# Changelog

All notable Matterhorn Import changes are tracked here. A version is not production-approved until the release gates documented in `README.md` and `docs/PRODUCTION.md` have executed successfully on that commit.

## Unreleased

## 0.1.7

- Enforced exclusive PrestaShop product ownership per shop with `uq_shop_product_owner (id_shop, id_product)` so two supplier sources cannot manage the same product silently.
- Added an idempotent retained-data/upgrade migration that fails closed when a legacy database already contains cross-source ownership conflicts instead of choosing an owner automatically.
- Removed ownership-sensitive `ON DUPLICATE KEY UPDATE` behavior from mapping persistence; exact owners update in place while foreign product-owner collisions now fail explicitly.
- Added runtime database safety validation for the exact unique ownership index and MariaDB regression coverage for duplicate-source ownership rejection and legacy conflict detection.
- Fenced image and new-product retry resets with `status='failed'` at UPDATE time so a stale retry candidate list cannot clear a worker lease acquired concurrently.
- Bypassed PrestaShop `Db` query caching for advisory locks, transaction-state decisions, mutable run state, worker lease/queue reads and Back Office live status reads.
- Made multishop image detach atomic with one guarded `DELETE ... INNER JOIN` statement instead of a racy pre-delete shop-count check.
- Rejected image URLs above 16 KiB before `parse_url`, DNS lookup or network access.
- Kept supplier normalization warnings observable in snapshot payloads/status without letting warning-only differences dirty catalog domain hashes.
- Removed the UPDATE fallback that routed payload-only metadata changes into unnecessary product core writes.
- Canonicalized supplier warning ordering so semantically identical option reordering does not churn snapshot payload hashes.
- Preserved Matterhorn `avaible_in` and `creation_date` as supplier metadata without assigning stock/delivery/date-add semantics or dirtying catalog domain hashes.
- Added regression coverage for warning/domain isolation, warning-order determinism, supplier metadata isolation, retry lease fencing, DB cache bypass, image URL bounds, atomic multishop detach and ownership schema safety.
- Made the GitHub Actions release workflow manually dispatchable when push-trigger execution is unavailable.

## 0.1.6

- Added bounded `matterhornimport:images:revalidate` scheduling for supplier image-content changes behind unchanged URLs.
- Reuses the secure persistent image worker and HTTP `ETag` / `Last-Modified` conditional requests instead of downloading every image on every import.
- Added `idx_revalidate (id_shop, source, updated_at, source_key)` for stale image-state discovery.
- Bounded stale discovery by product limit, a hard 50,000-row DB scan cap and the existing 8 MiB snapshot payload window.
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
