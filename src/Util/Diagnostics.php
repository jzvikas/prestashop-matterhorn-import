<?php
namespace Lp\MatterhornImport\Util;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Contract\CheckpointableSourceInterface;
use Lp\MatterhornImport\Contract\SourceInterface;

final class Diagnostics
{
    private const REQUIRED_EXTENSIONS = ['curl','fileinfo','xmlreader','simplexml','mbstring'];
    private const MODULE_TABLES = [
        'li_matterhornim_99dfbf_run','li_matterhornim_99dfbf_snapshot','li_matterhornim_99dfbf_mapping',
        'li_matterhornim_99dfbf_category_mapping','li_matterhornim_99dfbf_feature_mapping','li_matterhornim_99dfbf_feature_value_mapping',
        'li_matterhornim_99dfbf_feature_state','li_matterhornim_99dfbf_combination_mapping','li_matterhornim_99dfbf_specific_price_state',
        'li_matterhornim_99dfbf_new_product_queue','li_matterhornim_99dfbf_error','li_matterhornim_99dfbf_image_state','li_matterhornim_99dfbf_image_queue',
        'li_matterhornim_99dfbf_image_orphan','li_matterhornim_99dfbf_attribute_group_mapping','li_matterhornim_99dfbf_attribute_value_mapping',
    ];

    public function __construct(private SourceInterface $source, private DatabaseSafety $databaseSafety, private OperationalSettings $settings) {}

    public function run(int $shopId): array
    {
        $checks = [];
        $db = \Db::getInstance();
        $checks[] = $this->check('php', PHP_VERSION_ID >= 80400 ? 'ok' : 'error', 'PHP ' . PHP_VERSION);
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->check('ext:' . $extension, $loaded ? 'ok' : 'error', $loaded ? 'loaded' : 'missing');
        }
        $psVersion = defined('_PS_VERSION_') ? (string)_PS_VERSION_ : '';
        $psOk = $psVersion !== '' && version_compare($psVersion, '9.1.0', '>=') && version_compare($psVersion, '9.2.0', '<');
        $checks[] = $this->check('prestashop', $psOk ? 'ok' : 'error', $psVersion !== '' ? $psVersion : 'unknown');
        try { $this->databaseSafety->assertTransactionalCore(); $checks[] = $this->check('core-db-engine','ok','critical tables are InnoDB'); }
        catch (\Throwable $e) { $checks[] = $this->check('core-db-engine','error',$e->getMessage()); }

        foreach (self::MODULE_TABLES as $table) {
            $engine = $db->getValue("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . pSQL(_DB_PREFIX_ . $table) . "'");
            $checks[] = $this->check('table:' . $table, strtoupper((string)$engine) === 'INNODB' ? 'ok' : 'error', $engine ? (string)$engine : 'missing');
        }

        foreach ([
            'li_matterhornim_99dfbf_run' => [
                'read_checkpoint','source_fingerprint','source_policy_hash',
                'image_reconcile_status','image_reconcile_checkpoint','image_reconcile_done',
            ],
            'li_matterhornim_99dfbf_mapping' => ['combination_stock_hash','out_of_feed','last_seen_run_id'],
            'li_matterhornim_99dfbf_image_queue' => ['id_run','available_at','locked_by','locked_until'],
            'li_matterhornim_99dfbf_new_product_queue' => ['id_run','id_product','available_at','locked_by','locked_until'],
        ] as $table => $requiredColumns) {
            $columns = $db->executeS(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . pSQL(_DB_PREFIX_ . $table) . "'"
            ) ?: [];
            $present = array_fill_keys(array_map(static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''), $columns), true);
            $missing = array_values(array_filter($requiredColumns, static fn(string $column): bool => !isset($present[$column])));
            $checks[] = $this->check(
                'schema:' . $table,
                $missing === [] ? 'ok' : 'error',
                $missing === [] ? 'required columns present' : 'missing: ' . implode(',', $missing)
            );
        }

        $queueIndexRows = $db->executeS(
            "SELECT COLUMN_NAME,SEQ_IN_INDEX FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
            "AND TABLE_NAME='" . pSQL(_DB_PREFIX_ . 'li_matterhornim_99dfbf_image_queue') . "' " .
            "AND INDEX_NAME='idx_shop_source_status' ORDER BY SEQ_IN_INDEX"
        ) ?: [];
        $queueIndex = array_map(static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''), $queueIndexRows);
        $expectedIndex = ['id_shop','source','status','id_queue'];
        $checks[] = $this->check(
            'image-source-queue-index',
            $queueIndex === $expectedIndex ? 'ok' : 'error',
            $queueIndex === $expectedIndex ? implode(',', $expectedIndex) : 'expected=' . implode(',', $expectedIndex) . ' actual=' . implode(',', $queueIndex)
        );

        $revalidateIndexRows = $db->executeS(
            "SELECT COLUMN_NAME,SEQ_IN_INDEX FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
            "AND TABLE_NAME='" . pSQL(_DB_PREFIX_ . 'li_matterhornim_99dfbf_image_state') . "' " .
            "AND INDEX_NAME='idx_revalidate' ORDER BY SEQ_IN_INDEX"
        ) ?: [];
        $revalidateIndex = array_map(static fn(array $row): string => (string)($row['COLUMN_NAME'] ?? ''), $revalidateIndexRows);
        $expectedRevalidateIndex = ['id_shop','source','updated_at','source_key'];
        $checks[] = $this->check(
            'image-revalidation-index',
            $revalidateIndex === $expectedRevalidateIndex ? 'ok' : 'error',
            $revalidateIndex === $expectedRevalidateIndex ? implode(',', $expectedRevalidateIndex) : 'expected=' . implode(',', $expectedRevalidateIndex) . ' actual=' . implode(',', $revalidateIndex)
        );

        $brokenGroups = (int)$db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_attribute_group_mapping` m ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.id_attribute_group=m.id_attribute_group ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_shop` ags ON ags.id_attribute_group=m.id_attribute_group AND ags.id_shop=m.id_shop ' .
            'WHERE m.id_shop=' . $shopId . ' AND (ag.id_attribute_group IS NULL OR ags.id_attribute_group IS NULL)'
        );
        $checks[] = $this->check('size-group-mappings', $brokenGroups === 0 ? 'ok' : 'error', 'broken=' . $brokenGroups);

        $brokenValues = (int)$db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_attribute_value_mapping` m ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute=m.id_attribute ' .
            'LEFT JOIN `' . _DB_PREFIX_ . 'attribute_shop` ash ON ash.id_attribute=m.id_attribute AND ash.id_shop=m.id_shop ' .
            'WHERE m.id_shop=' . $shopId . ' AND (a.id_attribute IS NULL OR ash.id_attribute IS NULL OR a.id_attribute_group<>m.id_attribute_group)'
        );
        $checks[] = $this->check('size-value-mappings', $brokenValues === 0 ? 'ok' : 'error', 'broken=' . $brokenValues);

        $packet = (int)$db->getValue('SELECT @@max_allowed_packet');
        $checks[] = $this->check('db:max-allowed-packet', $packet >= 16 * 1024 * 1024 ? 'ok' : 'warning', $packet > 0 ? round($packet / 1048576, 1) . ' MiB' : 'unknown');
        foreach ($this->settings->inspect($shopId) as $key => $setting) {
            $message = 'effective=' . $setting['effective'] . ' allowed=' . $setting['min'] . '..' . $setting['max'];
            if ($setting['raw'] === null) { $message .= ' (default)'; }
            elseif ($setting['valid']) { $message .= ' stored=' . $setting['raw']; }
            else { $message .= ' invalid_stored=' . $setting['raw'] . ' (default applied)'; }
            $checks[] = $this->check('config:' . strtolower($key), $setting['valid'] ? 'ok' : 'warning', $message);
        }
        foreach (['_PS_CACHE_DIR_','_PS_PROD_IMG_DIR_'] as $constant) {
            $path = defined($constant) ? (string)constant($constant) : '';
            $checks[] = $this->check('path:' . $constant, $path !== '' && is_dir($path) && is_writable($path) ? 'ok' : 'error', $path !== '' ? $path : 'constant missing');
        }
        try {
            if ($this->source instanceof CheckpointableSourceInterface) { $checks[] = $this->check('source','ok',$this->source->name() . ' fingerprint=' . substr($this->source->fingerprint(),0,12)); }
            else { $checks[] = $this->check('source','warning',$this->source->name() . ' has no fingerprint check'); }
        } catch (\Throwable $e) { $checks[] = $this->check('source','error',$e->getMessage()); }
        foreach (['image'=>'li_matterhornim_99dfbf_image_queue','new-product'=>'li_matterhornim_99dfbf_new_product_queue'] as $domain => $table) {
            $row = $db->getRow("SELECT COUNT(*) total,SUM(status='pending') pending,SUM(status='processing') processing,SUM(status='failed') failed,SUM(status='processing' AND locked_until IS NOT NULL AND locked_until<=NOW()) expired FROM `" . _DB_PREFIX_ . $table . '` WHERE id_shop=' . $shopId);
            $row = is_array($row) ? $row : [];
            $failed=(int)($row['failed']??0); $expired=(int)($row['expired']??0);
            $checks[]=$this->check($domain . '-queue',$failed>0||$expired>0?'warning':'ok',sprintf('total=%d pending=%d processing=%d failed=%d expired=%d',(int)($row['total']??0),(int)($row['pending']??0),(int)($row['processing']??0),$failed,$expired));
        }
        $orphanRow = $db->getRow('SELECT COUNT(*) total,SUM(available_at IS NULL OR available_at<=NOW()) due FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_orphan` WHERE id_shop=' . $shopId);
        $orphanRow = is_array($orphanRow) ? $orphanRow : [];
        $orphanTotal = (int)($orphanRow['total'] ?? 0);
        $checks[] = $this->check('image-orphans', $orphanTotal > 0 ? 'warning' : 'ok', sprintf('total=%d due=%d', $orphanTotal, (int)($orphanRow['due'] ?? 0)));

        $latest=$db->getRow('SELECT id_run,status,read_status,import_status,update_status,remove_status,image_reconcile_status,image_reconcile_checkpoint,image_reconcile_done FROM `' . _DB_PREFIX_ . "li_matterhornim_99dfbf_run` WHERE id_shop=" . $shopId . " AND source='" . pSQL($this->source->name()) . "' ORDER BY id_run DESC");
        $checks[] = !$latest ? $this->check('latest-run','warning','no runs yet') : $this->check(
            'latest-run',
            (string)$latest['status']==='failed' || (string)($latest['image_reconcile_status'] ?? '')==='failed' ? 'warning' : 'ok',
            sprintf(
                '#%d %s [%s/%s/%s/%s] image_reconcile=%s done=%d checkpoint=%s',
                (int)$latest['id_run'],(string)$latest['status'],(string)$latest['read_status'],(string)$latest['import_status'],
                (string)$latest['update_status'],(string)$latest['remove_status'],(string)($latest['image_reconcile_status'] ?? 'pending'),
                (int)($latest['image_reconcile_done'] ?? 0),(string)(($latest['image_reconcile_checkpoint'] ?? '') ?: '-')
            )
        );
        return $checks;
    }

    private function check(string $name,string $status,string $message): array { return ['name'=>$name,'status'=>$status,'message'=>$message]; }
}
