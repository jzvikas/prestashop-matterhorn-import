<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Import\ImportStage;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportCommand extends Command
{
    public function __construct(private SourceInterface $source, private ImportStage $stage, private ImportLock $lock)
    {
        parent::__construct('matterhornimport:import');
    }

    protected function configure(): void
    {
        $this->setDescription('Create new Matterhorn products from a completed READ snapshot')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'READ run ID')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Concrete shop ID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size', '500')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum attempted products; 0 = unlimited', '0')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft runtime limit in seconds; 0 = unlimited', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shop = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $runId = CommandInput::positiveInt($input->getOption('run'), '--run');
        $batch = CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $maxItems = CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $source = $this->source->name();
        if (!$this->lock->acquire($shop, $source)) { throw new \RuntimeException('Matterhorn import already running for this shop'); }
        try {
            $completed = $this->stage->run($runId, $shop, $source, $batch, $maxItems, $timeLimit);
            $result = ['run'=>$runId,'shop'=>$shop,'source'=>$source,'stage'=>'import','status'=>$completed?'completed':'paused'];
            if ((bool) $input->getOption('json')) { $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
            else { $output->writeln($completed ? '<info>IMPORT completed.</info>' : '<comment>IMPORT paused safely; resume with the same run id.</comment>'); }
            return Command::SUCCESS;
        } finally { $this->lock->release(); }
    }
}
