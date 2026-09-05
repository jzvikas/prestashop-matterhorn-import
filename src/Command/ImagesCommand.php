<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Image\ImageWorker;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImagesCommand extends Command
{
    public function __construct(private ImageWorker $worker)
    {
        parent::__construct('matterhornimport:images');
    }

    protected function configure(): void
    {
        $this
            ->addOption('shop', null, InputOption::VALUE_OPTIONAL, 'Only this shop')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Jobs per tick', '20')
            ->addOption('worker', null, InputOption::VALUE_REQUIRED, 'Worker label', gethostname() . '-' . getmypid())
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Seconds; 0 = single tick', '0')
            ->addOption('idle-sleep-ms', null, InputOption::VALUE_REQUIRED, 'Sleep between empty polls', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::optionalPositiveInt($input->getOption('shop'), '--shop');
        $limit = CommandInput::positiveInt($input->getOption('limit'), '--limit', 500);
        $maxRuntime = CommandInput::nonNegativeInt($input->getOption('max-runtime'), '--max-runtime', 86400);
        $idleSleepMs = CommandInput::nonNegativeInt($input->getOption('idle-sleep-ms'), '--idle-sleep-ms', 60000);
        $worker = CommandInput::workerLabel($input->getOption('worker'));
        $started = microtime(true);
        $total = ['processed'=>0,'done'=>0,'failed'=>0,'lost'=>0,'deduplicated'=>0,'not_modified'=>0];

        do {
            $result = $this->worker->tick($worker, $limit, $shopId);
            foreach (array_keys($total) as $key) { $total[$key] += (int) ($result[$key] ?? 0); }
            if ($maxRuntime === 0) { break; }
            if ((int) ($result['processed'] ?? 0) === 0) { usleep($idleSleepMs * 1000); }
        } while ((microtime(true) - $started) < $maxRuntime);

        $json = json_encode($total, JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode image worker result'); }
        $output->writeln($json);
        return $total['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
