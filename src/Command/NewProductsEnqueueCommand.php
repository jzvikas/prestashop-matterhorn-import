<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Repository\NewProductQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Util\CommandInput;
use Lp\MatterhornImport\Util\ExecutionBudget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class NewProductsEnqueueCommand extends Command
{
    private const DEFAULT_MAX_ITEMS = 50000;
    private const DEFAULT_TIME_LIMIT = 30;

    public function __construct(
        private SourceInterface $source,
        private RunRepository $runs,
        private NewProductQueueRepository $queue,
        private ExecutionBudget $budget
    ) {
        parent::__construct('matterhornimport:new-products:enqueue');
    }

    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_REQUIRED)
            ->addOption('shop', null, InputOption::VALUE_REQUIRED)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Snapshot page size', '500')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximum rows enqueued this invocation; 0 = unlimited', (string) self::DEFAULT_MAX_ITEMS)
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Soft execution limit in seconds; 0 = unlimited', (string) self::DEFAULT_TIME_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = CommandInput::positiveInt($input->getOption('run'), '--run');
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $batch = CommandInput::positiveInt($input->getOption('batch'), '--batch', 2000);
        $maxItems = CommandInput::nonNegativeInt($input->getOption('max-items'), '--max-items', 1000000000);
        $timeLimit = CommandInput::nonNegativeInt($input->getOption('time-limit'), '--time-limit', 86400);
        $source = $this->source->name();
        $run = $this->runs->assertContext($runId, $shopId, $source);
        if ((string) $run['read_status'] !== 'completed') { throw new \RuntimeException('READ must complete before enqueueing new products'); }
        if ((string) $run['remove_status'] !== 'pending') { throw new \RuntimeException('Cannot enqueue new products after REMOVE has started'); }

        $this->budget->start($maxItems, $timeLimit);
        $cursor = '';
        $enqueued = 0;
        $pages = 0;
        while (!$this->budget->shouldStop()) {
            $pageLimit = $batch;
            if ($maxItems > 0) {
                $remaining = $maxItems - $this->budget->processed();
                if ($remaining <= 0) { break; }
                $pageLimit = min($pageLimit, $remaining);
            }

            $rows = $this->queue->nextUnqueuedRows($runId, $shopId, $source, $cursor, $pageLimit);
            if ($rows === []) { break; }
            $jobs = [];
            foreach ($rows as $row) {
                $cursor = (string) $row['source_key'];
                $jobs[] = [
                    'source_key'=>$cursor,
                    'payload'=>(string)$row['payload'],
                    'payload_hash'=>(string)$row['payload_hash'],
                ];
            }
            $enqueued += $this->queue->enqueueBatch($runId, $shopId, $source, $jobs);
            foreach ($rows as $_row) { $this->budget->markItem(); }
            $pages++;
        }

        $paused = $this->budget->shouldStop();
        $json = json_encode([
            'run'=>$runId,
            'shop'=>$shopId,
            'source'=>$source,
            'enqueued'=>$enqueued,
            'pages'=>$pages,
            'paused'=>$paused,
            'reason'=>$paused ? $this->budget->reason() : null,
            'cursor'=>$cursor,
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode new-product enqueue result'); }
        $output->writeln($json);
        return Command::SUCCESS;
    }
}
