<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Tests\Unit\Stamp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Zenstruck\Messenger\Monitor\Stamp\AttributeStamp;
use Zenstruck\Messenger\Monitor\Stamp\DisableMonitoringStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;

final class AttributeStampTest extends TestCase
{
    /** @test */
    public function envelope_stamps_take_precedence_after_attributes_have_been_cached(): void
    {
        $message = new #[TagStamp('attribute')] #[DisableMonitoringStamp] class {};
        $envelope = new Envelope($message);
        $this->assertSame('attribute', TagStamp::firstFrom($envelope)->value);
        $this->assertNotNull(DisableMonitoringStamp::firstFrom($envelope));

        $first = new TagStamp('first');
        $envelope = $envelope->with($first, new TagStamp('second'));
        $this->assertSame($first, TagStamp::firstFrom($envelope));
        $this->assertSame(['first', 'second', 'attribute'], \array_map(
            static fn(TagStamp $stamp) => $stamp->value,
            \iterator_to_array(TagStamp::from($envelope)),
        ));
        $this->assertNull(TagStamp::firstFrom(new Envelope(new \stdClass())));
    }

    /** @test */
    public function cached_metadata_creates_fresh_attribute_instances(): void
    {
        $envelope = new Envelope(new #[MutableAttributeStamp] class {});
        $first = MutableAttributeStamp::firstFrom($envelope);
        $first->value = 'changed';

        $second = MutableAttributeStamp::firstFrom($envelope);
        $this->assertNotSame($first, $second);
        $this->assertSame('original', $second->value);
        $this->assertSame('original', \iterator_to_array(MutableAttributeStamp::from($envelope))[0]->value);
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class MutableAttributeStamp extends AttributeStamp
{
    public string $value = 'original';
}
