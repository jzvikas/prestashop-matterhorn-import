<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Import\RemoveStage;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RemoveCommand extends Command
{
    public function __construct(private SourceInterface $source, private RemoveStage $stage, private ImportLock $lock, private OperationalSettings $settings) { parent::__construct('matterhornimport:remove'); }
    protected function configure(): void
    {
        $this->setDescription('Deactivate Matterhorn products missing from a validated feed')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run ID')->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Concrete shop ID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size; omitted = shop setting')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum attempted products; omitted = shop setting')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft seconds limit; omitted = shop setting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable result')->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview REMOVE candidates without changing data');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shop = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $run = CommandInput::positiveInt($input->getOption('run'), '--run');
        $batch = $input->getOption('batch') === null ? $this->settings->batchSize($shop) : CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $maxItems = $input->getOption('max-items') === null ? $this->settings->maxItems($shop) : CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = $input->getOption('time-limit') === null ? $this->settings->timeLimit($shop) : CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $source = $this->source->name();
        if (!$this->lock->acquire($shop, $source)) { throw new \RuntimeException('Matterhorn import already running for this shop'); }
        try {
            if ((bool)$input->getOption('dry-run')) {
                $plan = $this->stage->plan($run, $shop, $source);
                $payload = ['run'=>$run,'shop'=>$shop,'source'=>$source,'mapped'=>$plan['mapped'],'candidates'=>$plan['candidates'],'percent'=>round($plan['percent'],2),'max_percent'=>$plan['max_percent'],'safe'=>$plan['safe'],'dry_run'=>true];
                $output->writeln((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return $plan['safe'] ? Command::SUCCESS : Command::FAILURE;
            }
            $completed = $this->stage->run($run, $shop, $source, $batch, $maxItems, $timeLimit);
            $payload = ['run'=>$run,'shop'=>$shop,'source'=>$source,'stage'=>'remove','status'=>$completed?'completed':'paused'];
            if ((bool)$input->getOption('json')) { $output->writeln((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
            else { $output->writeln($completed ? '<info>REMOVE completed.</info>' : '<comment>REMOVE paused safely; resume with the same run id.</comment>'); }
            return Command::SUCCESS;
        } finally { $this->lock->release(); }
    }
}
