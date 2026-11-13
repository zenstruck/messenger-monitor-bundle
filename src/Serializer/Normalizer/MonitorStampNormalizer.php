<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\Serializer\Normalizer;

use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class MonitorStampNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait, NormalizerAwareTrait;

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $data['dispatchedAt'] = $this->denormalizer->denormalize($data['dispatchedAt'], \DateTimeImmutable::class, $format, $context);

        if (isset($data['receivedAt'])) {
            $data['receivedAt'] = $this->denormalizer->denormalize($data['receivedAt'], \DateTimeImmutable::class, $format, $context);
        }

        if (isset($data['finishedAt'])) {
            $data['finishedAt'] = $this->denormalizer->denormalize($data['finishedAt'], \DateTimeImmutable::class, $format, $context);
        }

        return MonitorStamp::from($data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return MonitorStamp::class === $type && \is_array($data);
    }

    /**
     * @param MonitorStamp $object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null // @phpstan-ignore-line
    {
        return $this->normalizer->normalize($object->toArray());
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MonitorStamp;
    }

    /**
     * @return mixed[]
     */
    public function getSupportedTypes(?string $format): array
    {
        return [MonitorStamp::class => true];
    }
}
