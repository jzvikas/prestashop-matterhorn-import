<?php
namespace Lp\MatterhornImport\Matterhorn;

use Lp\MatterhornImport\Config\MatterhornPolicy;
use Lp\MatterhornImport\Contract\SizeResolverInterface;

final class MatterhornSizeResolver implements SizeResolverInterface
{
    private const PRESTASHOP_GENERIC_TEXT_PATTERN = '/^[^<>{}]*$/u';

    public function __construct(
        private readonly ?MatterhornPolicy $policy = null,
        private readonly string $fallbackGroupName = 'Size'
    ) {}

    public function attribute(string $size): array
    {
        $display = trim(preg_replace('/\s+/u', ' ', $size) ?? $size);
        if ($display === '') {
            throw new \InvalidArgumentException('Matterhorn size cannot be empty');
        }
        if (preg_match(self::PRESTASHOP_GENERIC_TEXT_PATTERN, $display) !== 1) {
            throw new \InvalidArgumentException(
                'Matterhorn size contains characters rejected by PrestaShop (<, >, {, })'
            );
        }
        if (strlen($display) > 128) {
            throw new \InvalidArgumentException('Matterhorn size exceeds PrestaShop 128-byte limit');
        }

        $policy = $this->policy?->current();
        $groupName = trim((string) ($policy['size_attribute_group_name'] ?? $this->fallbackGroupName));
        if ($groupName === '' || strlen($groupName) > 64) {
            throw new \InvalidArgumentException('Matterhorn Size group name is invalid');
        }
        if (preg_match(self::PRESTASHOP_GENERIC_TEXT_PATTERN, $groupName) !== 1) {
            throw new \InvalidArgumentException(
                'Matterhorn Size group name contains characters rejected by PrestaShop (<, >, {, })'
            );
        }

        $identity = mb_strtolower($display, 'UTF-8');

        return [
            'group_key' => 'matterhorn:size',
            'value_key' => 'matterhorn:size:' . $identity,
            'group_name' => $groupName,
            'value' => $display,
        ];
    }
}
