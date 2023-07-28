<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @immutable
 *
 * @phpstan-type Input = array{
 *     from?: \DateTimeImmutable|string|null,
 *     to?: \DateTimeImmutable|string|null,
 *     status?: self::SUCCESS|self::FAILED|null,
 *     message_type?: ?string,
 *     transport?: ?string,
 *     tag?: ?string,
 *     not_tag?: ?string,
 * }
 */
final class Specification
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    public const ONE_HOUR_AGO = '1-hour-ago';
    public const ONE_DAY_AGO = '24-hours-ago';
    public const ONE_WEEK_AGO = '7-days-ago';
    public const ONE_MONTH_AGO = '30-days-ago';
    public const DATE_PRESETS = [
        self::ONE_HOUR_AGO,
        self::ONE_DAY_AGO,
        self::ONE_WEEK_AGO,
        self::ONE_MONTH_AGO,
    ];

    private const DATE_PRESET_MAP = [
        self::ONE_HOUR_AGO => '-1 hour',
        self::ONE_DAY_AGO => '-1 day',
        self::ONE_WEEK_AGO => '-1 week',
        self::ONE_MONTH_AGO => '-30 days',
    ];

    /** @var self::SUCCESS|self::FAILED|null */
    private ?string $status = null;
    private ?\DateTimeImmutable $from = null;
    private ?\DateTimeImmutable $to = null;
    private ?string $messageType = null;
    private ?string $transport = null;
    private ?string $tag = null;
    private ?string $notTag = null;

    public static function new(): self
    {
        return new self();
    }

    /**
     * @param Input|self|null $values
     */
    public static function create(self|array|null $values): self
    {
        if ($values instanceof self) {
            return $values;
        }

        if (!\is_array($values)) {
            $values = [];
        }

        $specification = new self();
        $specification->from = self::parseDate($values['from'] ?? null);
        $specification->to = self::parseDate($values['to'] ?? null);
        $specification->messageType = $values['message_type'] ?? null;
        $specification->transport = $values['transport'] ?? null;
        $specification->tag = $values['tag'] ?? null;
        $specification->notTag = $values['not_tag'] ?? null;
        $specification->status = match ($values['status'] ?? null) {
            self::SUCCESS => self::SUCCESS,
            self::FAILED => self::FAILED,
            default => null,
        };

        return $specification;
    }

    public function from(string|\DateTimeImmutable|null $value): self
    {
        $clone = clone $this;
        $clone->from = self::parseDate($value);

        return $clone;
    }

    public function to(string|\DateTimeImmutable|null $value): self
    {
        $clone = clone $this;
        $clone->to = self::parseDate($value);

        return $clone;
    }

    public function for(?string $messageType): self
    {
        $clone = clone $this;
        $clone->messageType = $messageType;

        return $clone;
    }

    public function on(?string $transport): self
    {
        $clone = clone $this;
        $clone->transport = $transport;

        return $clone;
    }

    public function with(?string $tag): self
    {
        $clone = clone $this;
        $clone->tag = $tag;

        return $clone;
    }

    public function without(?string $tag): self
    {
        $clone = clone $this;
        $clone->notTag = $tag;

        return $clone;
    }

    public function successes(): self
    {
        $clone = clone $this;
        $clone->status = self::SUCCESS;

        return $clone;
    }

    public function failures(): self
    {
        $clone = clone $this;
        $clone->status = self::FAILED;

        return $clone;
    }

    /**
     * @return array{
     *     from: ?\DateTimeImmutable,
     *     to: ?\DateTimeImmutable,
     *     status: self::SUCCESS|self::FAILED|null,
     *     message_type: ?string,
     *     transport: ?string,
     *     tag: ?string,
     *     not_tag: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->status,
            'message_type' => $this->messageType,
            'transport' => $this->transport,
            'tag' => $this->tag,
            'not_tag' => $this->notTag,
        ];
    }

    public function snapshot(Storage $storage): Snapshot
    {
        return new Snapshot($storage, $this);
    }

    private static function parseDate(string|\DateTimeImmutable|null $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return $value;
        }

        return new \DateTimeImmutable(self::DATE_PRESET_MAP[$value] ?? $value);
    }
}
