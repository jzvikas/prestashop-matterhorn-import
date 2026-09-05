<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Import\ReadStage;
use Lp\MatterhornImport\Lock\ImportLock;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ReadCommand extends Command
{
    public function __construct(private SourceInterface $source, private RunRepository $runs, private ReadStage $stage, private ImportLock $lock)
    {
        parent::__construct('matterhornimport:read');
    }

    protected function configure(): void
    {
        $this->setDescription('Build or resume the Matterhorn normalized staging snapshot')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Concrete shop ID')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Resume an existing READ run ID')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum source rows; 0 = unlimited', '0')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft runtime limit in seconds; 0 = unlimited', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shop = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $requestedRun = CommandInput::optionalPositiveInt($input->getOption('run'), '--run');
        $maxItems = CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $source = $this->source->name();
        if (!$this->lock->acquire($shop, $source)) { throw new \RuntimeException('Matterhorn import already running for this shop'); }
        try {
            if ($requestedRun !== null) {
                $runId = $requestedRun;
                $this->runs->assertContext($runId, $shop, $source);
            } else {
                $runId = $this->runs->create($shop, $source);
            }
            $completed = $this->stage->run($runId, $maxItems, $timeLimit);
            $payload = ['run' => $runId, 'shop' => $shop, 'source' => $source, 'stage' => 'read', 'status' => $completed ? 'completed' : 'paused'];
            if ((bool) $input->getOption('json')) {
                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $output->writeln($completed ? '<info>READ completed for run #' . $runId . '</info>' : '<comment>READ paused safely for run #' . $runId . '; resume with --run=' . $runId . '</comment>');
            }
            return Command::SUCCESS;
        } finally {
            $this->lock->release();
        }
    }
}
