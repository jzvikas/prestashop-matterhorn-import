<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Config\OperationalSettings;
use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Repository\ErrorRepository;
use Lp\MatterhornImport\Repository\ImageOrphanRepository;
use Lp\MatterhornImport\Repository\ImageQueueRepository;
use Lp\MatterhornImport\Repository\NewProductQueueRepository;
use Lp\MatterhornImport\Repository\RunRepository;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends Command
{
    public function __construct(
        private SourceInterface $source,
        private RunRepository $runs,
        private ImageQueueRepository $images,
        private ImageOrphanRepository $imageOrphans,
        private NewProductQueueRepository $newProducts,
        private ErrorRepository $errors,
        private OperationalSettings $settings
    ) {
        parent::__construct('matterhornimport:status');
    }
    protected function configure(): void { $this->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $source = $this->source->name();
        $run = $this->runs->latest($shopId, $source);
        $runId = $run ? (int)$run['id_run'] : 0;
        $payload = [
            'shop_id'=>$shopId,'source'=>$source,'effective_settings'=>$this->settings->values($shopId),'run'=>$run,
            'issues_total'=>$runId > 0 ? $this->errors->countForRun($runId) : 0,
            'errors_total'=>$runId > 0 ? $this->errors->countErrorsForRun($runId) : 0,
            'warnings_total'=>$runId > 0 ? $this->errors->countWarningsForRun($runId) : 0,
            'images'=>$this->images->counts($shopId),
            'image_orphans'=>$this->imageOrphans->count($shopId, $source),
            'new_products'=>$this->newProducts->counts($shopId),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Could not encode Matterhorn status'); }
        $output->writeln($json);
        return Command::SUCCESS;
    }
}
