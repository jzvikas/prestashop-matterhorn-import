<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Repository\NewProductQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Repository\SnapshotRepository;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class NewProductsEnqueueCommand extends Command
{
    public function __construct(private SourceInterface $source, private RunRepository $runs, private SnapshotRepository $snapshots, private NewProductQueueRepository $queue)
    {
        parent::__construct('matterhornimport:new-products:enqueue');
    }

    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_REQUIRED)
            ->addOption('shop', null, InputOption::VALUE_REQUIRED)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Snapshot page size', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = CommandInput::positiveInt($input->getOption('run'), '--run');
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $batch = CommandInput::positiveInt($input->getOption('batch'), '--batch', 5000);
        $source = $this->source->name();
        $run = $this->runs->assertContext($runId, $shopId, $source);
        if ((string) $run['read_status'] !== 'completed') { throw new \RuntimeException('READ must complete before enqueueing new products'); }
        if ((string) $run['remove_status'] !== 'pending') { throw new \RuntimeException('Cannot enqueue new products after REMOVE has started'); }

        $cursor = '';
        $enqueued = 0;
        while (true) {
            $rows = $this->snapshots->newRows($runId, $shopId, $source, $cursor, $batch);
            if ($rows === []) { break; }
            $jobs = [];
            foreach ($rows as $row) {
                $cursor = (string) $row['source_key'];
                $jobs[] = ['source_key'=>$cursor,'payload'=>(string)$row['payload'],'payload_hash'=>(string)$row['payload_hash']];
            }
            $enqueued += $this->queue->enqueueBatch($runId, $shopId, $source, $jobs);
        }

        $json = json_encode(['run'=>$runId,'shop'=>$shopId,'source'=>$source,'enqueued'=>$enqueued], JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode new-product enqueue result'); }
        $output->writeln($json);
        return Command::SUCCESS;
    }
}
