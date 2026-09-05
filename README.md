# Matterhorn Wholesale Import

Standalone high-throughput Matterhorn Wholesale supplier import module for **PrestaShop 9.1.x** and **PHP 8.4+**, based on the reusable architecture from `jzvikas/prestashop-import-skeleton`.

## Status

Work in progress. The first implementation slice establishes the standalone module identity, Matterhorn XML streaming adapter, mapper, category path normalization, HTML sanitization, Size resolution contract/PrestaShop resolver, deterministic fixture and PHP 8.4 CI. Full skeleton orchestration/DB/image/new-product infrastructure is still being restored into this standalone module and must be green before PROD is declared.

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
| `option_name` | PrestaShop `Size` attribute value |
| `STOCK` | combination quantity |
| `ean` | combination EAN13 when valid |
| `avaible_in` | supplier metadata only; not stock |

## Streaming model

Matterhorn XML is read with `XMLReader` and `LIBXML_NONET`; only one `<product>` payload is materialized at a time. The adapter supports record checkpoint resume and a source fingerprint. It never downloads images during READ.

## Development checks

```bash
composer validate --no-check-publish
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
bash tests/static-release-check.sh
```

The current CI uses PHP 8.4 and verifies PHP syntax plus the Matterhorn parser/mapper fixture and core domain-hash isolation.

## Planned final CLI

The completed module will expose the generated skeleton command set under the `matterhornimport:` prefix, including `doctor`, `run`, `read`, `import`, `update`, `remove`, `images`, `images:reconcile`, `new-products:enqueue`, `new-products`, `retry`, `status` and `gc`.

## Production rule

Do not treat this repository as production-ready until the complete PHP 8.4 / MariaDB / real PrestaShop 9.1.5 lifecycle, multishop, image, retry/recovery and READ -> IMPORT -> UPDATE -> REMOVE gates are green.
