<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runnerPath = $root . '/src/Import/ImportRunner.php';
$commandPath = $root . '/src/Command/RunCommand.php';
$servicesPath = $root . '/config/services.yml';
foreach ([$runnerPath, $commandPath, $servicesPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, 'Missing RUN orchestration file: ' . basename($path) . "\n");
        exit(1);
    }
}

$runner = file_get_contents($runnerPath);
$command = file_get_contents($commandPath);
$services = file_get_contents($servicesPath);
$checks = [
    [$runner, 'runBounded(', 'bounded runner entrypoint'],
    [$runner, '$this->lock->acquire($shopId, $source)', 'single shop/source lock'],
    [$runner, '$this->runs->assertContext($runId, $shopId, $source)', 'resume context validation'],
    [$runner, '$run[\'read_status\']', 'READ stage gate'],
    [$runner, '$this->read->run(', 'READ invocation'],
    [$runner, '$run[\'import_status\']', 'IMPORT stage gate'],
    [$runner, '$this->import->run(', 'IMPORT invocation'],
    [$runner, '$run[\'update_status\']', 'UPDATE stage gate'],
    [$runner, '$this->update->run(', 'UPDATE invocation'],
    [$runner, '$run[\'remove_status\']', 'REMOVE stage gate'],
    [$runner, '$this->remove->run(', 'REMOVE invocation'],
    [$runner, "'status' => 'paused'", 'safe pause result'],
    [$runner, 'finally {', 'lock release finally'],
    [$runner, '$this->lock->release()', 'lock release'],
    [$command, "parent::__construct('matterhornimport:run')", 'run CLI name'],
    [$command, "->addOption('run'", 'run resume option'],
    [$command, "->addOption('max-items'", 'global item budget option'],
    [$command, "->addOption('time-limit'", 'global time budget option'],
    [$services, 'Lp\\MatterhornImport\\Command\\RunCommand:', 'run service registration'],
];
foreach ($checks as [$haystack, $needle, $label]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$readPos = strpos($runner, '$this->read->run(');
$importPos = strpos($runner, '$this->import->run(');
$updatePos = strpos($runner, '$this->update->run(');
$removePos = strpos($runner, '$this->remove->run(');
if ($readPos === false || $importPos === false || $updatePos === false || $removePos === false || !($readPos < $importPos && $importPos < $updatePos && $updatePos < $removePos)) {
    fwrite(STDERR, "FAIL: RUN stage order\n");
    exit(1);
}
if (str_contains($runner, 'ImageWorker') || str_contains($runner, 'NewProductWorker')) {
    fwrite(STDERR, "FAIL: RUN must keep image/new-product workers in independent lanes\n");
    exit(1);
}

echo "RUN orchestration contract: OK\n";
