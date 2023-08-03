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
 *     period?: Period|string,
 *     from?: \DateTimeImmutable|string|null,
 *     to?: \DateTimeImmutable|string|null,
 *     status?: self::SUCCESS|self::FAILED|null,
 *     message_type?: ?string,
 *     transport?: ?string,
 *     tags?: string[]|string|null,
 *     not_tags?: string[]|string|null,
 *     sort?: self::ASC|self::DESC,
 * }
 */
final class Specification
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    public const ASC = 'asc';
    public const DESC = 'desc';

    /** @var self::SUCCESS|self::FAILED|null */
    private ?string $status = null;
    private ?\DateTimeImmutable $from = null;
    private ?\DateTimeImmutable $to = null;
    private ?string $messageType = null;
    private ?string $transport = null;

    /** @var self::ASC|self::DESC */
    private string $sort = self::DESC;

    /** @var string[] */
    private array $tags = [];

    /** @var string[] */
    private array $notTags = [];

    public static function new(): self
    {
        return new self();
    }

    /**
     * @param Input|self|string|Period|null $values
     */
    public static function create(self|array|string|Period|null $values): self
    {
        if ($values instanceof self) {
            return $values;
        }

        if ($values instanceof Period || \is_string($values)) {
            $values = ['period' => Period::parseOrFail($values)];
        }

        if (!\is_array($values)) {
            $values = [];
        }

        $specification = new self();

        if (isset($values['period'])) {
            [$values['from'], $values['to']] = Period::parse($values['period'])->timestamps();
        }

        $specification->from = self::parseDate($values['from'] ?? null);
        $specification->to = self::parseDate($values['to'] ?? null);
        $specification->messageType = $values['message_type'] ?? null;
        $specification->transport = $values['transport'] ?? null;
        $specification->status = match ($values['status'] ?? null) {
            self::SUCCESS => self::SUCCESS,
            self::FAILED => self::FAILED,
            default => null,
        };
        $specification->sort = match ($values['sort'] ?? null) {
            self::ASC => self::ASC,
            default => self::DESC,
        };

        if (isset($values['tags'])) {
            $specification->tags = (array) $values['tags'];
        }

        if (isset($values['not_tags'])) {
            $specification->notTags = (array) $values['not_tags'];
        }

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

    public function with(string ...$tags): self
    {
        $clone = clone $this;
        $clone->tags = $tags;

        return $clone;
    }

    public function without(string ...$tags): self
    {
        $clone = clone $this;
        $clone->notTags = $tags;

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

    public function sortAscending(): self
    {
        $clone = clone $this;
        $clone->sort = self::ASC;

        return $clone;
    }

    public function sortDescending(): self
    {
        $clone = clone $this;
        $clone->sort = self::DESC;

        return $clone;
    }

    /**
     * @return array{
     *     from: ?\DateTimeImmutable,
     *     to: ?\DateTimeImmutable,
     *     status: self::SUCCESS|self::FAILED|null,
     *     message_type: ?string,
     *     transport: ?string,
     *     tags: string[],
     *     not_tags: string[],
     *     sort: self::ASC|self::DESC,
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
            'tags' => $this->tags,
            'not_tags' => $this->notTags,
            'sort' => $this->sort,
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

        return new \DateTimeImmutable($value);
    }
}
