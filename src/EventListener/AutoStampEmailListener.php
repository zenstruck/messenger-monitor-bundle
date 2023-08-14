<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\EventListener;

use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class AutoStampEmailListener
{
    public function __invoke(MessageEvent $event): void
    {
        if (!$event->isQueued()) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $tag = $message->getHeaders()->get('X-Tag');

        if ($tag instanceof TagHeader) {
            $event->addStamp(new TagStamp($tag->getValue()));
        }

        if (!$subject = $message->getSubject()) {
            return;
        }

        $description = $subject;

        if (1 === \count($to = $message->getTo())) {
            $description = \sprintf('"%s" to "%s"', $description, $to[0]->toString());
        }

        $event->addStamp(new DescriptionStamp($description));
    }
}
