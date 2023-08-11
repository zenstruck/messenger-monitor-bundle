<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class Component
{
    public bool $live = true;

    public function __construct(public readonly ViewHelper $helper)
    {
    }

    public function __invoke(
        #[Autowire(param: 'zenstruck_messenger_monitor.security_role')]
        ?string $role,
        ?Security $security = null,
    ): void {
        if ($security && $role && !$security->isGranted($role)) {
            throw new AccessDeniedException();
        }
    }
}
