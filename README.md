# Matterhorn Wholesale Import

Standalone high-throughput Matterhorn Wholesale supplier import module for **PrestaShop 9.1.x** and **PHP 8.4+**, based on the reusable architecture from `jzvikas/prestashop-import-skeleton`.

## Status

Work in progress. The supplier adapter has a streaming Matterhorn XML reader, semantic mapper, category path normalization, safe HTML handling and **pure READ-time Size descriptors**. READ no longer queries or mutates PrestaShop attributes. Numeric Size attribute IDs are resolved later by the generic skeleton persistence resolver. READ -> IMPORT -> UPDATE -> REMOVE orchestration and the canonical run/snapshot/mapping schema are restored. The image pipeline foundation now includes the persistent queue/state model, batched enqueue, renewable lease fencing, conditional HTTP revalidation and the skeleton-grade SSRF/DNS/MIME/byte/pixel download protections. Image attachment/reconciliation workers, global new-product/retry/GC/status/BO and real PrestaShop lifecycle coverage still remain before PROD can be declared.

Primary build specification: [`MATTERHORN_IMPORT_BUILD_PROMPT.md`](MATTERHORN_IMPORT_BUILD_PROMPT.md).

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

## Development checks

```bash
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bash tests/static-release-check.sh
```

The current CI uses PHP 8.4 and verifies PHP syntax, supplier parser/mapper/domain-hash behavior, persistence/orchestration contracts and the secure image-pipeline foundation.

## Planned final CLI

The completed module will expose the generated skeleton command set under the `matterhornimport:` prefix, including `doctor`, `run`, `read`, `import`, `update`, `remove`, `images`, `images:reconcile`, `new-products:enqueue`, `new-products`, `retry`, `status` and `gc`.

## Production rule

Do not treat this repository as production-ready until the complete PHP 8.4 / MariaDB / real PrestaShop 9.1.5 lifecycle, multishop, image, retry/recovery and READ -> IMPORT -> UPDATE -> REMOVE gates are green.
