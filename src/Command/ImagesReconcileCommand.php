<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Image\ImageReconciler;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImagesReconcileCommand extends Command
{
    public function __construct(private ImageReconciler $reconciler)
    {
        parent::__construct('matterhornimport:images:reconcile');
    }

    protected function configure(): void
    {
        $this->setDescription('Reconcile Matterhorn-owned image order, cover and stale images after a completed run')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Completed import run ID')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Target shop ID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per keyset page', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = CommandInput::positiveInt($input->getOption('run'), '--run');
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $batch = CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $output->writeln((string) json_encode(
            $this->reconciler->run($runId, $shopId, $batch),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        return Command::SUCCESS;
    }
}
