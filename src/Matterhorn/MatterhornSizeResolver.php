<?php
namespace Lp\MatterhornImport\Matterhorn;

use Lp\MatterhornImport\Contract\SizeResolverInterface;

final class MatterhornSizeResolver implements SizeResolverInterface
{
    public function __construct(private readonly string $groupName = 'Size')
    {
    }

    public function attribute(string $size): array
    {
        $display = trim(preg_replace('/\s+/u', ' ', $size) ?? $size);
        if ($display === '') {
            throw new \InvalidArgumentException('Matterhorn size cannot be empty');
        }
        if (strlen($display) > 128) {
            throw new \InvalidArgumentException('Matterhorn size exceeds PrestaShop 128-byte limit');
        }

        $groupName = trim($this->groupName);
        if ($groupName === '' || strlen($groupName) > 64) {
            throw new \InvalidArgumentException('Matterhorn Size group name is invalid');
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
