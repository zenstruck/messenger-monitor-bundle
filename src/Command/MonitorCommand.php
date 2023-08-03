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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
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
    public function __construct(
        private WorkerMonitor $workers,
        private TransportMonitor $transports,
        private ?Storage $storage = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        if (!$this->storage) {
            return;
        }

        $this
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'From date', Period::IN_LAST_DAY->value, [...Period::inLastValues(), ...Period::absoluteValues()])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Messenger Monitor Overview');

        if (!$output instanceof ConsoleOutputInterface || !$input->isInteractive()) {
            return $this->render($io, $input);
        }

        $io = new SymfonyStyle($input, $section = $output->section());

        while (true) { // @phpstan-ignore-line
            $this->render($io, $input);
            $io->writeln('<comment>! [NOTE] Press CTRL+C to quit</comment>');

            \sleep(1);
            $section->clear();
        }
    }

    private function render(SymfonyStyle $io, InputInterface $input): int
    {
        $this->renderWorkerStatus($io);
        $io->writeln('');
        $this->renderTransportStatus($io);
        $io->writeln('');

        if ($this->storage) {
            $this->renderSnapshot($io, $input);
            $io->writeln('');
        }

        return self::SUCCESS;
    }

    private function renderSnapshot(SymfonyStyle $io, InputInterface $input): void
    {
        $period = Period::parseOrFail($input->getOption('period'));
        $snapshot = Specification::create($period)->snapshot($this->storage); // @phpstan-ignore-line
        $waitTime = $snapshot->averageWaitTime();
        $handlingTime = $snapshot->averageHandlingTime();
        $failRate = \round($snapshot->failRate() * 100);
        $total = $snapshot->totalCount();

        if ($fails = $snapshot->failureCount()) {
            $total .= \sprintf(' (<error>%s</error> failed)', $fails);
        }

        $io->createTable()
            ->setHorizontal()
            ->setHeaderTitle('Historical Snapshot')
            ->setHeaders([
                'Period',
                'Messages Processed',
                'Fail Rate',
                'Avg. Wait Time',
                'Avg. Handling Time',
                'Handled Per Minute',
                'Handled Per Hour',
                'Handled Per Day',
            ])
            ->addRow([
                $period->humanize(),
                $total,
                match (true) {
                    $failRate < 5 => \sprintf('<info>%s%%</info>', $failRate),
                    $failRate < 10 => \sprintf('<comment>%s%%</comment>', $failRate),
                    default => \sprintf('<error>%s%%</error>', $failRate),
                },
                $waitTime ? Helper::formatTime($snapshot->averageWaitTime()) : 'n/a',
                $handlingTime ? Helper::formatTime($snapshot->averageHandlingTime()) : 'n/a',
                \round($snapshot->handledPerMinute(), 2),
                \round($snapshot->handledPerHour(), 2),
                \round($snapshot->handledPerDay(), 2),
            ])
            ->render()
        ;
    }

    private function renderWorkerStatus(SymfonyStyle $io): void
    {
        $table = $io->createTable()
            ->setHeaderTitle('Messenger Workers')
            ->setHeaders(['Status', 'Up Time', 'Transports', 'Queues', 'Messages', 'Memory'])
        ;

        if (!$workers = $this->workers->all()) {
            $table->addRow([new TableCell('<error>[!] No workers running.</error>', [
                'colspan' => 6,
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
                $info->messagesHandled(),
                (string) $info->memoryUsage(),
            ],
            $workers,
        ));

        $table->render();
    }

    private function renderTransportStatus(SymfonyStyle $io): void
    {
        $table = $io->createTable()
            ->setHeaderTitle('Messenger Transports')
            ->setHeaders(['Name', 'Queued Messages', 'Workers'])
        ;

        if (!$transports = $this->transports->all()) {
            $table->addRow([new TableCell('<error>[!] No transports configured.</error>', [
                'colspan' => 3,
                'style' => new TableCellStyle(['align' => 'center']),
            ])]);
            $table->render();

            return;
        }

        $table->addRows(\array_map(
            static fn(TransportInfo $info) => [
                $info->name(),
                $info->isCountable() ? \count($info) : '<comment>n/a</comment>',
                \count($info->workers()),
            ],
            $transports
        ));

        $table->render();
    }
}
