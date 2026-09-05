<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Contract\SourceInterface;
use Lp\MatterhornImport\Image\ImageRevalidationScheduler;
use Lp\MatterhornImport\Util\CommandInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImagesRevalidateCommand extends Command
{
    public function __construct(private ImageRevalidationScheduler $scheduler, private SourceInterface $source)
    {
        parent::__construct('matterhornimport:images:revalidate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Schedule bounded conditional revalidation for stale Matterhorn image states')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Target shop ID')
            ->addOption('age-hours', null, InputOption::VALUE_REQUIRED, 'Only revalidate image states at least this old', '24')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum stale products to inspect/schedule', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $ageHours = CommandInput::positiveInt($input->getOption('age-hours'), '--age-hours', 87600);
        $limit = CommandInput::positiveInt($input->getOption('limit'), '--limit', 5000);
        $result = $this->scheduler->schedule($shopId, $this->source->name(), $ageHours, $limit);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { throw new \RuntimeException('Could not encode image revalidation result'); }
        $output->writeln($json);
        return Command::SUCCESS;
    }
}
