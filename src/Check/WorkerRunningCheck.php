<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Check;

use Liip\Monitor\Check;
use Liip\Monitor\Result;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Liip\Monitor\AsCheck;

#[AsCheck(id: 'symfony_worker_running', suite: 'messenger-monitoring')]
class WorkerRunningCheck implements Check, \Stringable
{
    public function __construct(private WorkerCache $workerCache)
    {}

    public function __toString(): string
    {
        return 'symfony_worker_running';
        #return 'Symfony Workers running';
    }

    public function run(): Result
    {

        #$this->workerCache->getIterator();

        /*
        if ($this->version->isEol()) {
            return Result::failure(
                \sprintf('%s - the %s branch is EOL', $this->version->currentVersion(), $this->version->branch()),
                context: ['eol_date' => $this->version->supportUntil()]
            );
        }

        if ($this->version->isPatchUpdateRequired()) {
            return Result::warning(
                \sprintf('%s - requires a patch update to %s', $this->version->currentVersion(), $this->version->latestPatchVersion()),
                context: ['latest_patch_version' => $this->version->latestPatchVersion()]
            );
        }*/

        return Result::success("Actual running: ". 2);
    }

    public static function configInfo(): ?string
    {
        return 'check if the minimum amount of symfony workers is running';
    }

    protected function detail(): string
    {
        return 'Details';
        #return \sprintf('Disk (%s) is %s used (%s of %s total)', $this->path, $storage->percentUsed(), $storage->used(), $storage->total());
    }

    /*
    public static function load(array $config, ContainerBuilder $container): void
    {
        if (!$config['enabled']) {
            return;
        }

        var_dump("AAAAA");

        $container->register(\sprintf('.liip_monitor.check.%s', static::configKey()), static::class)
            ->setArguments([
                new Reference('liip_monitor.info.symfony_worker_running'),
            ])
            ->addTag('liip_monitor.check', $config);
    }*/
}


