<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Gc\GcService;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GcCommand extends Command
{
    public function __construct(private GcService $gc)
    {
        parent::__construct('matterhornimport:gc');
    }

    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_OPTIONAL, 'Only this shop')
            ->addOption('keep-run', null, InputOption::VALUE_REQUIRED, 'Keep snapshots from this run onward', '0')
            ->addOption('image-days', null, InputOption::VALUE_REQUIRED, 'Keep completed image jobs days', '2')
            ->addOption('new-product-days', null, InputOption::VALUE_REQUIRED, 'Keep completed mapped new-product jobs days', '7')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Rows per DELETE chunk', '2000')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Max rows per invocation; 0 unlimited', '50000')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft seconds limit; 0 unlimited', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::optionalPositiveInt($input->getOption('shop'), '--shop');
        $stats = $this->gc->run(
            CommandInput::nonNegativeInt($input->getOption('keep-run'), '--keep-run'),
            CommandInput::nonNegativeInt($input->getOption('image-days'), '--image-days', 3650),
            CommandInput::nonNegativeInt($input->getOption('new-product-days'), '--new-product-days', 3650),
            CommandInput::positiveInt($input->getOption('chunk'), '--chunk', 10000),
            CommandInput::nonNegativeInt($input->getOption('max-rows'), '--max-rows', 10000000),
            CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400),
            $shopId
        );
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('shop=%s images=%d new_products=%d snapshots=%d image_state=%d total=%d paused=%s reason=%s', $stats['shop_id'] === null ? 'all' : (string)$stats['shop_id'], $stats['images'], $stats['new_products'], $stats['snapshots'], $stats['image_state'], $stats['total'], $stats['paused'] ? 'yes' : 'no', $stats['reason'] ?? '-'));
        }
        return Command::SUCCESS;
    }
}
