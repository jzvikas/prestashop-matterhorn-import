<?php
namespace Lp\MatterhornImport\Source;

use Lp\MatterhornImport\Contract\ByteCheckpointableSourceInterface;
use Lp\MatterhornImport\Contract\RunScopedSourceInterface;

final class ConfiguredMatterhornXmlSource implements ByteCheckpointableSourceInterface, RunScopedSourceInterface
{
    private ?MatterhornXmlSource $delegate = null;
    private ?MatterhornXmlSource $runDelegate = null;
    private ?string $remoteFingerprint = null;
    private ?string $runFingerprint = null;
    private int $activeRunId = 0;
    private int $activeShopId = 0;

    public function __construct(
        private SourceLocation $locations,
        private RemoteFeedMaterializer $materializer,
        private RunSourceSnapshotManager $runSnapshots
    ) {
    }

    public function name(): string
    {
        return 'matterhorn';
    }

    public function rows(): iterable
    {
        yield from ($this->runDelegate ?? $this->delegate())->rows();
    }

    public function rowsFrom(int $offset): iterable
    {
        if ($this->runDelegate !== null) {
            $checkpoint = $this->runSnapshots->checkpoint($this->activeRunId, $this->activeShopId);
            if ($checkpoint !== null && $checkpoint['record'] === $offset) {
                yield from $this->runDelegate->rowsFromByte($checkpoint['byte'], $offset);
                return;
            }

            // Crash-safe fallback only. The DB record checkpoint is authoritative;
            // if the tiny byte sidecar is missing after a successful DB commit,
            // Prewk scans those already committed product nodes once and recreates
            // the byte cursor on the next flush.
            yield from $this->runDelegate->rowsFrom($offset);
            return;
        }

        yield from $this->delegate()->rowsFrom($offset);
    }

    public function rowsFromByte(int $byteOffset, int $recordOffset = 0): iterable
    {
        yield from ($this->runDelegate ?? $this->delegate())->rowsFromByte($byteOffset, $recordOffset);
    }

    public function byteCheckpoint(): int
    {
        return ($this->runDelegate ?? $this->delegate())->byteCheckpoint();
    }

    public function fingerprint(): string
    {
        if ($this->runFingerprint !== null) {
            return $this->runFingerprint;
        }

        $location = $this->configuredLocation();
        if (!$this->locations->isRemote($location)) {
            return $this->delegate()->fingerprint();
        }
        if ($this->remoteFingerprint !== null) {
            return $this->remoteFingerprint;
        }

        $path = $this->materializedPath($location);
        $this->remoteFingerprint = $this->fingerprintRemote($location, $path);
        $this->delegate = new MatterhornXmlSource($path);

        return $this->remoteFingerprint;
    }

    public function activateRun(int $runId, bool $resume): void
    {
        if ($runId <= 0) {
            throw new \InvalidArgumentException('Matterhorn run source requires a positive run ID');
        }

        [$shopId] = $this->shopIdentity();
        $snapshot = $resume ? $this->runSnapshots->load($runId, $shopId) : null;
        if ($snapshot === null) {
            $location = $this->configuredLocation();
            $remote = $this->locations->isRemote($location);
            $path = $remote ? $this->materializedPath($location) : $location;
            $fingerprint = $remote
                ? $this->fingerprintRemote($location, $path)
                : (new MatterhornXmlSource($path))->fingerprint();

            $snapshot = $this->runSnapshots->create(
                $runId,
                $shopId,
                $path,
                $fingerprint,
                $remote
            );
        }

        $this->activeRunId = $runId;
        $this->activeShopId = $shopId;
        $this->runFingerprint = $snapshot['fingerprint'];
        $this->runDelegate = new MatterhornXmlSource($snapshot['path']);
        $this->delegate = null;
        $this->remoteFingerprint = null;
    }

    public function persistRunCheckpoint(int $runId, int $recordCheckpoint, int $byteCheckpoint): void
    {
        if ($runId !== $this->activeRunId || $this->activeShopId <= 0) {
            throw new \RuntimeException('Matterhorn run-source checkpoint context mismatch');
        }

        $this->runSnapshots->persistCheckpoint(
            $runId,
            $this->activeShopId,
            $recordCheckpoint,
            $byteCheckpoint
        );
    }

    public function releaseRun(int $runId): void
    {
        if ($runId <= 0) {
            return;
        }

        $shopId = $this->activeRunId === $runId && $this->activeShopId > 0
            ? $this->activeShopId
            : (int) (\Context::getContext()->shop->id ?? 0);
        if ($shopId > 0) {
            $this->runSnapshots->release($runId, $shopId);
        }

        if ($this->activeRunId === $runId) {
            $this->activeRunId = 0;
            $this->activeShopId = 0;
            $this->runFingerprint = null;
            $this->runDelegate = null;
        }
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
        [$shopId] = $this->shopIdentity();

        return $this->materializer->materialize($location, $shopId);
    }

    private function configuredLocation(): string
    {
        [$shopId, $shopGroupId] = $this->shopIdentity();
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

    private function fingerprintRemote(string $location, string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Could not fingerprint downloaded Matterhorn XML.');
        }

        return hash('sha256', 'url:' . $location . '|sha256:' . $hash);
    }

    /** @return array{0:int,1:int} */
    private function shopIdentity(): array
    {
        $shop = \Context::getContext()->shop;
        $shopId = (int) ($shop->id ?? 0);
        $shopGroupId = (int) ($shop->id_shop_group ?? 0);
        if ($shopId <= 0 || $shopGroupId <= 0) {
            throw new \RuntimeException('Matterhorn source requires a concrete shop context.');
        }

        return [$shopId, $shopGroupId];
    }
}
