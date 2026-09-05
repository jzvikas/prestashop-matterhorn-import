<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\NewProduct\NewProductWorker;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class NewProductsCommand extends Command
{
    public function __construct(private NewProductWorker $worker, private OperationalSettings $settings)
    {
        parent::__construct('matterhornimport:new-products');
    }

    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_OPTIONAL, 'Only this shop')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per tick; omitted = shop setting')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Worker label', (gethostname() ?: 'host') . '-' . getmypid())
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Seconds; omitted = shop setting, 0 = single tick')
            ->addOption('idle-sleep-ms', null, InputOption::VALUE_REQUIRED, 'Sleep between empty polls', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::optionalPositiveInt($input->getOption('shop'), '--shop');
        $limit = $input->getOption('limit') === null ? ($shopId === null ? 20 : $this->settings->newProductWorkerLimit($shopId)) : CommandInput::positiveInt($input->getOption('limit'), '--limit', 200);
        $worker = CommandInput::workerLabel($input->getOption('worker'));
        $maxRuntime = $input->getOption('max-runtime') === null ? ($shopId === null ? 0 : $this->settings->newProductWorkerRuntime($shopId)) : CommandInput::nonNegativeInt($input->getOption('max-runtime'), '--max-runtime', 86400);
        $idleSleepMs = CommandInput::nonNegativeInt($input->getOption('idle-sleep-ms'), '--idle-sleep-ms', 60000);
        $started = microtime(true);
        $total = [
            'processed'=>0,'done'=>0,'failed'=>0,'deferred'=>0,'lost'=>0,
            'generation_requeued'=>0,'generation_adopted'=>0,'stale_superseded'=>0,
            'existing_updated'=>0,'recovered'=>0,'hook_commit_recoveries'=>0,
        ];
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
