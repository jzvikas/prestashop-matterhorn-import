<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/autoload.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) { require_once __DIR__ . '/vendor/autoload.php'; }

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Installer;

class MatterhornImport extends Module
{
    public function __construct()
    {
        $this->name = 'matterhornimport';
        $this->tab = 'administration';
        $this->version = '0.1.3';
        $this->author = 'LP';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('Matterhorn Wholesale Import', [], 'Modules.Matterhornimport.Admin');
        $this->description = $this->trans('High-throughput Matterhorn Wholesale supplier import for PrestaShop 9.1.x.', [], 'Modules.Matterhornimport.Admin');
        $this->ps_versions_compliancy = ['min' => '9.1.0', 'max' => '9.1.99'];
    }

    public function install(): bool { return parent::install() && (new Installer())->install(); }
    public function uninstall(): bool { return (new Installer())->uninstall() && parent::uninstall(); }

    public function getContent(): string
    {
        $shopId = (int) ($this->context->shop->id ?? 0);
        $shopGroupId = (int) ($this->context->shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            return $this->displayError($this->trans('Select one concrete shop before configuring Matterhorn Import.', [], 'Modules.Matterhornimport.Admin'));
        }

        $settings = new OperationalSettings();
        $languages = $this->languageOptions($shopId);
        $output = '';
        if (\Tools::isSubmit('submitMatterhornImport')) {
            try {
                $source = trim((string) \Tools::getValue('MATTERHORNIMPORT_SOURCE_FILE', ''));
                $sourceLanguageId = filter_var(trim((string) \Tools::getValue('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', '0')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $categoryAutoCreate = filter_var(trim((string) \Tools::getValue('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', '1')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
                $featureAutoCreate = filter_var(trim((string) \Tools::getValue('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', '1')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
                $sizeGroup = trim((string) \Tools::getValue('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', 'Size'));
                $maxRemove = filter_var(trim((string) \Tools::getValue('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', '25')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                if ($source !== '' && (!is_file($source) || !is_readable($source))) { throw new \InvalidArgumentException('Source XML file is not readable.'); }
                if ($sourceLanguageId === false || !isset($languages[(int) $sourceLanguageId])) { throw new \InvalidArgumentException('Source language must belong to the selected shop.'); }
                if ($categoryAutoCreate === false || $featureAutoCreate === false) { throw new \InvalidArgumentException('Auto-create policies must be 0 or 1.'); }
                if ($sizeGroup === '') { throw new \InvalidArgumentException('Size attribute group name cannot be empty.'); }
                if ($maxRemove === false) { throw new \InvalidArgumentException('Maximum REMOVE percentage must be an integer from 1 to 100.'); }

                $rawOperational = [];
                foreach ($settings->values($shopId) as $key => $_value) { $rawOperational[$key] = \Tools::getValue($key); }
                $validatedOperational = $settings->validate($rawOperational);

                $ok = \Configuration::updateValue('MATTERHORNIMPORT_SOURCE_FILE', $source, false, $shopGroupId, $shopId);
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', (string) $sourceLanguageId, false, $shopGroupId, $shopId) && $ok;
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', (string) $categoryAutoCreate, false, $shopGroupId, $shopId) && $ok;
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', (string) $featureAutoCreate, false, $shopGroupId, $shopId) && $ok;
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', $sizeGroup, false, $shopGroupId, $shopId) && $ok;
                $ok = \Configuration::updateValue('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', (string) $maxRemove, false, $shopGroupId, $shopId) && $ok;
                if (!$ok) { throw new \RuntimeException('Could not save core Matterhorn settings.'); }
                $settings->save($shopId, $shopGroupId, $validatedOperational);
                $output .= $this->displayConfirmation($this->trans('Matterhorn settings saved for this shop.', [], 'Modules.Matterhornimport.Admin'));
            } catch (\Throwable $e) {
                $output .= $this->displayError(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }

        $source = (string) \Configuration::get('MATTERHORNIMPORT_SOURCE_FILE', null, $shopGroupId, $shopId);
        $sourceLanguageId = (int) \Configuration::get('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', null, $shopGroupId, $shopId);
        if (!isset($languages[$sourceLanguageId])) {
            $sourceLanguageId = (int) \Configuration::get('PS_LANG_DEFAULT', null, $shopGroupId, $shopId);
        }
        if (!isset($languages[$sourceLanguageId]) && $languages !== []) {
            $sourceLanguageId = (int) array_key_first($languages);
        }
        $categoryAutoCreate = $this->boolConfig('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', $shopGroupId, $shopId, true);
        $featureAutoCreate = $this->boolConfig('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', $shopGroupId, $shopId, true);
        $sizeGroup = (string) \Configuration::get('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', null, $shopGroupId, $shopId);
        if ($sizeGroup === '') { $sizeGroup = 'Size'; }
        $maxRemove = (int) \Configuration::get('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', null, $shopGroupId, $shopId);
        if ($maxRemove < 1 || $maxRemove > 100) { $maxRemove = 25; }
        $operational = $settings->values($shopId);

        $output .= $this->renderStatus($shopId);
        $output .= '<div class="panel"><h3>' . $this->trans('Matterhorn Wholesale Import settings', [], 'Modules.Matterhornimport.Admin') . '</h3><form method="post">';
        $output .= $this->field('MATTERHORNIMPORT_SOURCE_FILE', 'Source XML file', $source, 'text');
        $output .= $this->selectField('MATTERHORNIMPORT_SOURCE_LANGUAGE_ID', 'Supplier/source language', $sourceLanguageId, $languages, 'CREATE fills required shop languages from the supplier value as fallback; UPDATE changes only this supplier-owned language.');
        $output .= $this->selectField('MATTERHORNIMPORT_CATEGORY_AUTO_CREATE', 'Auto-create missing categories', $categoryAutoCreate ? 1 : 0, [1 => 'Yes', 0 => 'No']);
        $output .= $this->selectField('MATTERHORNIMPORT_FEATURE_AUTO_CREATE', 'Auto-create Color/Type features', $featureAutoCreate ? 1 : 0, [1 => 'Yes', 0 => 'No']);
        $output .= $this->field('MATTERHORNIMPORT_SIZE_ATTRIBUTE_GROUP_NAME', 'Size attribute group', $sizeGroup, 'text');
        $output .= $this->field('MATTERHORNIMPORT_MAX_REMOVE_PERCENT', 'Maximum REMOVE percentage', (string) $maxRemove, 'number', 1, 100, 'REMOVE is blocked when missing feed products exceed this percentage of currently in-feed mapped products.');
        $output .= '<hr><h4>Operational limits</h4>';
        $labels = [
            OperationalSettings::BATCH_SIZE => ['Stage batch size', 1, 10000],
            OperationalSettings::MAX_ITEMS => ['Maximum items per invocation (0 = unlimited)', 0, 1000000000],
            OperationalSettings::TIME_LIMIT => ['Soft runtime limit seconds (0 = unlimited)', 0, 86400],
            OperationalSettings::IMAGE_WORKER_LIMIT => ['Image jobs per tick', 1, 500],
            OperationalSettings::IMAGE_WORKER_RUNTIME => ['Image worker runtime seconds (0 = one tick)', 0, 86400],
            OperationalSettings::NEW_PRODUCT_WORKER_LIMIT => ['New-product jobs per tick', 1, 200],
            OperationalSettings::NEW_PRODUCT_WORKER_RUNTIME => ['New-product worker runtime seconds (0 = one tick)', 0, 86400],
            OperationalSettings::RETRY_LIMIT => ['Retry reset limit per domain', 1, 100000],
        ];
        foreach ($labels as $key => [$label, $min, $max]) { $output .= $this->field($key, $label, (string) $operational[$key], 'number', $min, $max); }
        $output .= '<button class="btn btn-primary" type="submit" name="submitMatterhornImport">' . $this->trans('Save', [], 'Modules.Matterhornimport.Admin') . '</button></form></div>';
        $output .= '<div class="panel"><h3>Recommended CLI lanes</h3><pre>' . htmlspecialchars("# Product cycle\nphp bin/console matterhornimport:run --shop={$shopId}\n\n# Independent workers\nphp bin/console matterhornimport:new-products --shop={$shopId}\nphp bin/console matterhornimport:images --shop={$shopId}\n\n# Operations\nphp bin/console matterhornimport:retry --shop={$shopId}\nphp bin/console matterhornimport:gc --shop={$shopId}\nphp bin/console matterhornimport:doctor --shop={$shopId}\nphp bin/console matterhornimport:status --shop={$shopId}", ENT_QUOTES, 'UTF-8') . '</pre></div>';
        return $output;
    }

    private function renderStatus(int $shopId): string
    {
        $db = \Db::getInstance();
        $run = $db->getRow("SELECT id_run,status,read_status,import_status,update_status,remove_status,started_at,finished_at FROM `" . _DB_PREFIX_ . "li_matterhornim_99dfbf_run` WHERE id_shop=" . $shopId . " AND source='matterhorn' ORDER BY id_run DESC");
        $queue = [];
        foreach (['images' => 'li_matterhornim_99dfbf_image_queue', 'new products' => 'li_matterhornim_99dfbf_new_product_queue'] as $label => $table) {
            $rows = $db->executeS('SELECT status,COUNT(*) qty FROM `' . _DB_PREFIX_ . $table . '` WHERE id_shop=' . $shopId . ' GROUP BY status') ?: [];
            $counts = [];
            foreach ($rows as $row) { $counts[(string) $row['status']] = (int) $row['qty']; }
            $queue[] = $label . ': pending=' . ($counts['pending'] ?? 0) . ', processing=' . ($counts['processing'] ?? 0) . ', failed=' . ($counts['failed'] ?? 0) . ', done=' . ($counts['done'] ?? 0);
        }
        $orphanCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'li_matterhornim_99dfbf_image_orphan` WHERE id_shop=' . $shopId);
        $queue[] = 'image recovery orphans=' . $orphanCount;
        $runText = !$run ? 'No import run yet.' : sprintf('#%d %s — READ %s / IMPORT %s / UPDATE %s / REMOVE %s', (int) $run['id_run'], (string) $run['status'], (string) $run['read_status'], (string) $run['import_status'], (string) $run['update_status'], (string) $run['remove_status']);
        return '<div class="panel"><h3>Current shop status</h3><p><strong>' . htmlspecialchars($runText, ENT_QUOTES, 'UTF-8') . '</strong></p><p>' . htmlspecialchars(implode(' | ', $queue), ENT_QUOTES, 'UTF-8') . '</p></div>';
    }

    /** @return array<int,string> */
    private function languageOptions(int $shopId): array
    {
        $options = [];
        foreach (\Language::getLanguages(false, $shopId) as $language) {
            $id = (int) ($language['id_lang'] ?? 0);
            if ($id <= 0) { continue; }
            $label = trim((string) ($language['name'] ?? $language['iso_code'] ?? ('Language #' . $id)));
            $options[$id] = $label === '' ? 'Language #' . $id : $label;
        }
        if ($options === []) { throw new \RuntimeException('Selected shop has no active languages.'); }
        return $options;
    }

    private function boolConfig(string $key, int $shopGroupId, int $shopId, bool $default): bool
    {
        $raw = \Configuration::get($key, null, $shopGroupId, $shopId);
        if ($raw === false || $raw === null || $raw === '') { return $default; }
        return (int) $raw !== 0;
    }

    /** @param array<int,string> $options */
    private function selectField(string $name, string $label, int $value, array $options, string $help = ''): string
    {
        $html = '<div class="form-group"><label>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label><select class="form-control" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($options as $optionValue => $optionLabel) {
            $selected = (int) $optionValue === $value ? ' selected' : '';
            $html .= '<option value="' . (int) $optionValue . '"' . $selected . '>' . htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';
        if ($help !== '') { $html .= '<p class="help-block">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>'; }
        return $html;
    }

    private function field(string $name, string $label, string $value, string $type, ?int $min = null, ?int $max = null, string $help = ''): string
    {
        $limits = ($min === null ? '' : ' min="' . $min . '"') . ($max === null ? '' : ' max="' . $max . '"');
        $html = '<div class="form-group"><label>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label><input class="form-control" type="' . $type . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $limits . '></div>';
        if ($help !== '') { $html .= '<p class="help-block">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>'; }
        return $html;
    }
}
