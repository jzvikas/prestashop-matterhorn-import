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
        $this
            ->setDescription('Reconcile the latest completed Matterhorn image manifest with resumable checkpoints')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Completed import run ID')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Target shop ID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per keyset page', '500')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum products this invocation; 0 = unlimited', '0')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft runtime limit in seconds; 0 = unlimited', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = CommandInput::positiveInt($input->getOption('run'), '--run');
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $batch = CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $maxItems = CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $result = $this->reconciler->run($runId, $shopId, $batch, $maxItems, $timeLimit);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode image reconciliation result'); }
        $output->writeln($json);
        return ($result['status'] ?? '') === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
