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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Schedule\TaskInfo;
use Zenstruck\Messenger\Monitor\ScheduleMonitor;
use Zenstruck\Messenger\Monitor\Stamp\Tag;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsCommand('messenger:monitor:schedule:purge', 'Purge old schedule history')]
final class SchedulePurgeCommand extends Command
{
    public function __construct(private ScheduleMonitor $schedules, private Storage $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('schedule', InputArgument::IS_ARRAY, 'Schedule(s) to purge (defaults to all)', null, fn() => $this->schedules->names())
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Number of task histories to keep', 10)
            ->addOption('remove-orphans', null, InputOption::VALUE_NONE, 'Remove task histories that are no longer attached to a schedule')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command purges task history for the provided schedule
                (all by default) keeping the latest <comment>--keep</comment> of each.

                Use the <comment>--remove-orphans</comment> option to remove task histories that are no longer
                attached to a schedule (i.e. the task was removed/changed).
                EOF)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scheduleNames = $input->getArgument('schedule');
        $keep = $input->getOption('keep');

        if (!$scheduleNames) {
            $scheduleNames = $this->schedules->names();
        }

        foreach ($scheduleNames as $scheduleName) {
            $schedule = $this->schedules->get($scheduleName);
            $count = 0;

            $io->comment(\sprintf('Purging tasks from schedule <comment>%s</comment>, keeping latest <info>%d</info>', $scheduleName, $keep));

            foreach ($io->progressIterate($schedule) as $task) {
                /** @var TaskInfo $task */
                $spec = Specification::new()->with(Tag::forSchedule($task)->value);

                if ($this->storage->count($spec) <= $keep) {
                    continue; // not enough to purge
                }

                /** @var ProcessedMessage $oldest */
                $oldest = $spec->snapshot($this->storage)->messages()->take($keep)->eager()->reverse()->first();
                $spec = $spec->to($oldest->finishedAt()->modify('-1 second')); // ensure we don't remove the oldest

                $count += $this->storage->purge($spec);
            }

            $io->success(\sprintf('Purged %d tasks', $count));
        }

        if (!$input->getOption('remove-orphans')) {
            return self::SUCCESS;
        }

        $io->comment('Removing orphaned task histories');

        $spec = Specification::create([
            'tags' => 'schedule',
            'not_tags' => \iterator_to_array($this->validScheduleTags()),
        ]);

        $count = $this->storage->purge($spec);

        $io->success(\sprintf('Removed %d orphaned task histories', $count));

        return self::SUCCESS;
    }

    /**
     * @return \Traversable<string>
     */
    private function validScheduleTags(): \Traversable
    {
        foreach ($this->schedules as $schedule) {
            foreach ($schedule as $task) {
                yield Tag::forSchedule($task)->value;
            }
        }
    }
}
