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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\TransportMonitor;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
#[AsCommand('messenger:monitor:purge', 'Purge message history')]
final class PurgeCommand extends Command
{
    public function __construct(private Storage $storage, private TransportMonitor $transports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Older than', Period::OLDER_THAN_1_MONTH->value, Period::olderThanValues())
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status, "failed" or "success"', null, [Specification::SUCCESS, Specification::FAILED])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Message type')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Transport', null, fn() => $this->transports->names())
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tag(s)')
            ->addOption('not-tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '"Not" Tag(s)')
            ->addOption('exclude-schedules', null, null, 'Do not purge historical schedule data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $notTags = $input->getOption('not-tag');
        $period = Period::parseOrFail($input->getOption('older-than'));

        if ($input->getOption('exclude-schedules')) {
            $notTags[] = 'schedule';
        }

        $specification = Specification::create([
            'period' => $period,
            'status' => $input->getOption('status'),
            'message_type' => $input->getOption('type'),
            'transport' => $input->getOption('transport'),
            'tags' => $input->getOption('tag'),
            'not_tags' => $notTags,
        ]);

        $io->comment(\sprintf('Purging processed messages <info>%s</info>', $period->humanize()));

        $result = $this->storage->purge($specification);

        $io->success(\sprintf('Purged %s processed messages.', $result));

        return self::SUCCESS;
    }
}
