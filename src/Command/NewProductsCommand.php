<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\NewProduct\NewProductWorker;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class NewProductsCommand extends Command
{
    public function __construct(private NewProductWorker $worker)
    {
        parent::__construct('matterhornimport:new-products');
    }

    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_OPTIONAL, 'Only this shop')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per tick', '20')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Worker label', gethostname() . '-' . getmypid())
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Seconds; 0 = single tick', '0')
            ->addOption('idle-sleep-ms', null, InputOption::VALUE_REQUIRED, 'Sleep between empty polls', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::optionalPositiveInt($input->getOption('shop'), '--shop');
        $limit = CommandInput::positiveInt($input->getOption('limit'), '--limit', 200);
        $worker = CommandInput::workerLabel($input->getOption('worker'));
        $maxRuntime = CommandInput::nonNegativeInt($input->getOption('max-runtime'), '--max-runtime', 86400);
        $idleSleepMs = CommandInput::nonNegativeInt($input->getOption('idle-sleep-ms'), '--idle-sleep-ms', 60000);
        $started = microtime(true);
        $total = ['processed'=>0,'done'=>0,'failed'=>0,'deferred'=>0,'lost'=>0,'recovered'=>0,'hook_commit_recoveries'=>0];
        do {
            $result = $this->worker->tick($worker, $limit, $shopId);
            foreach (array_keys($total) as $key) { $total[$key] += (int) ($result[$key] ?? 0); }
            if ($maxRuntime === 0) { break; }
            if ((int) ($result['processed'] ?? 0) === 0) { usleep($idleSleepMs * 1000); }
        } while ((microtime(true) - $started) < $maxRuntime);

        $json = json_encode($total, JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode new-product worker result'); }
        $output->writeln($json);
        return $total['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
