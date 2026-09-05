<?php
namespace Lp\MatterhornImport\Command;

use Lp\MatterhornImport\Util\CommandInput;
use Lp\MatterhornImport\Util\Diagnostics;
use Lp\MatterhornImport\Util\ShopContextManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    public function __construct(private Diagnostics $diagnostics, private ShopContextManager $shopContext)
    {
        parent::__construct('matterhornimport:doctor');
    }

    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop', '1')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit only JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopId = CommandInput::positiveInt($input->getOption('shop'), '--shop');
        $this->shopContext->activate($shopId);
        $checks = $this->diagnostics->run($shopId);
        $errors = $warnings = 0;
        foreach ($checks as $check) {
            $status = strtoupper((string) $check['status']);
            if ($status === 'ERROR') { $errors++; } elseif ($status === 'WARNING') { $warnings++; }
            if (!(bool) $input->getOption('json')) { $output->writeln(sprintf('[%s] %s: %s', $status, $check['name'], $check['message'])); }
        }
        $json = json_encode(['shop'=>$shopId,'healthy'=>$errors===0,'checks_total'=>count($checks),'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { throw new \RuntimeException('Could not encode doctor result'); }
        $output->writeln($json);
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
