<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;
use Zenstruck\Messenger\Monitor\TransportMonitor;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;
use Zenstruck\Messenger\Monitor\WorkerMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsCommand('messenger:monitor', 'Display a status overview of your workers and transports')]
final class MonitorCommand extends Command
{
    public function __construct(private WorkerMonitor $workers, private TransportMonitor $transports)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Messenger Monitor Overview');

        if (!$output instanceof ConsoleOutputInterface || !$input->isInteractive()) {
            return $this->render($io);
        }

        $io = new SymfonyStyle($input, $section = $output->section());

        while (true) { // @phpstan-ignore-line
            $this->render($io);
            $io->writeln('');
            $io->writeln('<comment>! [NOTE] Press CTRL+C to quit</comment>');

            \sleep(1);
            $section->clear();
        }
    }

    private function render(SymfonyStyle $io): int
    {
        $this->renderWorkerStatus($io);
        $io->writeln('');
        $this->renderTransportStatus($io);
        $io->writeln('');

        return self::SUCCESS;
    }

    private function renderWorkerStatus(SymfonyStyle $io): void
    {
        $table = $io->createTable()
            ->setHeaderTitle('Messenger Workers')
            ->setHeaders(['Status', 'Up Time', 'Transports', 'Queues'])
        ;

        if (!$workers = $this->workers->all()) {
            $table->addRow([new TableCell('<error>[!] No workers running.</error>', [
                'colspan' => 4,
                'style' => new TableCellStyle(['align' => 'center']),
            ])]);
            $table->render();

            return;
        }

        $table->addRows(\array_map(
            static fn(WorkerInfo $info) => [
                \sprintf('<%s>%s</>', $info->isProcessing() ? 'comment' : 'info', $info->status()),
                Helper::formatTime($info->runningFor()),
                \implode(', ', $info->transports()),
                \implode(', ', $info->queues()) ?: 'n/a',
            ],
            $workers,
        ));

        $table->render();
    }

    private function renderTransportStatus(SymfonyStyle $io): void
    {
        $table = $io->createTable()
            ->setHeaderTitle('Messenger Transports')
            ->setHeaders(['Name', 'Queued Messages'])
        ;

        if (!$transports = $this->transports->all()) {
            $table->addRow([new TableCell('<error>[!] No transports configured.</error>', [
                'colspan' => 2,
                'style' => new TableCellStyle(['align' => 'center']),
            ])]);
            $table->render();

            return;
        }

        $table->addRows(\array_map(
            static fn(TransportInfo $info) => [
                $info->name(),
                $info->isCountable() ? \count($info) : '<comment>n/a</comment>',
            ],
            $transports
        ));

        $table->render();
    }
}
