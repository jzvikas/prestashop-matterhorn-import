<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\NewProductQueueRepository;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RetryCommand extends Command
{
    public function __construct(
        private ImageQueueRepository $images,
        private NewProductQueueRepository $newProducts,
        private OperationalSettings $settings,
        private SourceInterface $sourceAdapter
    ) {
        parent::__construct('matterhornimport:retry');
    }

    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Target shop ID')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'image, new-product or all', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max failed jobs per domain; omitted = shop setting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $limit = $input->getOption('limit') === null ? $this->settings->retryLimit($shopId) : CommandInput::positiveInt($input->getOption('limit'), '--limit', 100000);
        $domain = strtolower(trim((string) $input->getOption('domain')));
        if (!in_array($domain, ['image','new-product','all'], true)) { throw new \InvalidArgumentException('--domain must be image, new-product or all'); }
        $source = trim($this->sourceAdapter->name());
        if ($source === '') { throw new \RuntimeException('Retry source name is empty'); }
        $result = ['image'=>0,'new_product'=>0,'total'=>0];
        if ($domain === 'image' || $domain === 'all') { $result['image'] = $this->images->retryFailed($source, $shopId, $limit); }
        if ($domain === 'new-product' || $domain === 'all') { $result['new_product'] = $this->newProducts->retryFailed($source, $shopId, $limit); }
        $result['total'] = $result['image'] + $result['new_product'];
        if ((bool) $input->getOption('json')) {
            $json = json_encode($result, JSON_UNESCAPED_SLASHES);
            if ($json === false) { throw new \RuntimeException('Could not encode retry result'); }
            $output->writeln($json);
        } else {
            $output->writeln(sprintf('retried=%d image=%d new_product=%d', $result['total'], $result['image'], $result['new_product']));
        }
        return Command::SUCCESS;
    }
}
