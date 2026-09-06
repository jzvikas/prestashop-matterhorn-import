<?php
namespace Lp\MatterhornImport\Source;

use Lp\MatterhornImport\Contract\CheckpointableSourceInterface;

final class ConfiguredMatterhornXmlSource implements CheckpointableSourceInterface
{
    private ?MatterhornXmlSource $delegate = null;
    private ?string $remoteFingerprint = null;

    public function __construct(
        private SourceLocation $locations,
        private RemoteFeedMaterializer $materializer
    ) {
    }

    public function name(): string
    {
        return 'matterhorn';
    }

    public function rows(): iterable
    {
        yield from $this->delegate()->rows();
    }

    public function rowsFrom(int $offset): iterable
    {
        yield from $this->delegate()->rowsFrom($offset);
    }

    public function fingerprint(): string
    {
        $location = $this->configuredLocation();
        if (!$this->locations->isRemote($location)) {
            return $this->delegate()->fingerprint();
        }
        if ($this->remoteFingerprint !== null) {
            return $this->remoteFingerprint;
        }

        $path = $this->materializedPath($location);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Could not fingerprint downloaded Matterhorn XML.');
        }

        $this->delegate = new MatterhornXmlSource($path);
        $this->remoteFingerprint = hash('sha256', 'url:' . $location . '|sha256:' . $hash);

        return $this->remoteFingerprint;
    }

    private function delegate(): MatterhornXmlSource
    {
        if ($this->delegate !== null) {
            return $this->delegate;
        }

        $location = $this->configuredLocation();
        $path = $this->locations->isRemote($location)
            ? $this->materializedPath($location)
            : $location;

        $this->delegate = new MatterhornXmlSource($path);
        return $this->delegate;
    }

    private function materializedPath(string $location): string
    {
        $shopId = (int) (\Context::getContext()->shop->id ?? 0);
        if ($shopId <= 0) {
            throw new \RuntimeException('A concrete shop must be active before reading the remote Matterhorn source.');
        }

        return $this->materializer->materialize($location, $shopId);
    }

    private function configuredLocation(): string
    {
        $shop = \Context::getContext()->shop;
        $shopId = (int) ($shop->id ?? 0);
        $shopGroupId = (int) ($shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            throw new \RuntimeException('Matterhorn source requires a concrete shop context.');
        }

        $location = (string) \Configuration::get(
            'MATTERHORNIMPORT_SOURCE_FILE',
            null,
            $shopGroupId,
            $shopId
        );
        if (trim($location) === '') {
            $location = _PS_MODULE_DIR_ . 'matterhornimport/var/source.xml';
        }

        return $this->locations->validate($location);
    }
}
