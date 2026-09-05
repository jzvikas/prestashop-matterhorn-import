<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Import\ImportRunner;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunCommand extends Command
{
    public function __construct(private ImportRunner $runner, private OperationalSettings $settings)
    {
        parent::__construct('matterhornimport:run');
    }
    protected function configure(): void
    {
        $this->setDescription('Run or resume Matterhorn READ → IMPORT → UPDATE → REMOVE')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Concrete shop ID')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Resume an existing paused/failed run ID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per batch; omitted = shop setting')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum attempted items; omitted = shop setting')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft seconds limit; omitted = shop setting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable result');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $runId = CommandInput::optionalPositiveInt($input->getOption('run'), '--run');
        $batch = $input->getOption('batch') === null ? $this->settings->batchSize($shopId) : CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $maxItems = $input->getOption('max-items') === null ? $this->settings->maxItems($shopId) : CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = $input->getOption('time-limit') === null ? $this->settings->timeLimit($shopId) : CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $result = $this->runner->runBounded($shopId, $batch, $maxItems, $timeLimit, $runId);
        if ((bool)$input->getOption('json')) { $output->writeln((string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        elseif ($result['status'] === 'completed') { $output->writeln('<info>Completed Matterhorn run #' . $result['run'] . '</info>'); }
        else { $output->writeln('<comment>Run #' . $result['run'] . ' paused before/during ' . $result['stage'] . '; resume with --run=' . $result['run'] . '</comment>'); }
        return Command::SUCCESS;
    }
}
