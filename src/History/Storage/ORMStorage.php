<?php

/*
 * This file is part of the zenstruck/messenger-monitor-bundle package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Messenger\Monitor\History\Storage;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Messenger\Envelope;
use Zenstruck\Collection;
use Zenstruck\Collection\Doctrine\ORM\EntityResult;
use Zenstruck\Messenger\Monitor\History\Model\MessageTypeMetric;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 *
 * @template T of ProcessedMessage
 */
final class ORMStorage implements Storage
{
    /** @var EntityRepository<T> */
    private EntityRepository $repository;

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(private ManagerRegistry $registry, private string $entityClass)
    {
    }

    public function find(mixed $id): ?ProcessedMessage
    {
        return $this->repository()->find($id);
    }

    public function filter(Specification $specification): Collection
    {
        $queryBuilder = $this->queryBuilderFor($specification);
        $result = new EntityResult($queryBuilder);
        $manager = $queryBuilder->getEntityManager();
        $metadata = $manager->getClassMetadata($this->entityClass);
        $identifiers = $metadata->getIdentifierFieldNames();

        // Doctrine's pagination tree walkers require a single field as the identifier.
        if (1 !== \count($identifiers) || $metadata->hasAssociation($identifiers[0])) {
            return $result;
        }

        return $result->disableOutputWalkers();
    }

    public function purge(Specification $specification): int
    {
        return $this->queryBuilderFor($specification, order: false)->delete()->getQuery()->execute();
    }

    public function perMessageTypeMetrics(Specification $specification): Collection
    {
        $qb = $this->queryBuilderFor($specification->ignoreMessageType(), false)
            ->select('m.type')
            ->addSelect('COUNT(m.type) as total_count')
            ->addSelect('COUNT(m.failureType) as failure_count')
            ->addSelect('AVG(m.waitTime) AS avg_wait_time')
            ->addSelect('AVG(m.handleTime) AS avg_handling_time')
            ->groupBy('m.type')
        ;

        $totalSeconds = $specification->snapshot($this)->totalSeconds();

        return (new EntityResult($qb))
            ->as(static fn(array $data) => new MessageTypeMetric(
                $data['type'],
                $data['total_count'],
                $data['failure_count'],
                (int) $data['avg_wait_time'],
                (int) $data['avg_handling_time'],
                $totalSeconds,
            ))
        ;
    }

    public function save(Envelope $envelope, Results $results, ?\Throwable $exception = null): void
    {
        $om = $this->om();
        $object = new $this->entityClass($envelope, $results, $exception);

        $om->persist($object);
        $om->flush();
    }

    public function delete(mixed $id): void
    {
        if (!$message = $this->find($id)) {
            return;
        }

        $om = $this->om();

        $om->remove($message);
        $om->flush();
    }

    public function availableMessageTypes(Specification $specification): Collection
    {
        $qb = $this->queryBuilderFor($specification->ignoreMessageType(), false)
            ->select('DISTINCT m.type')
            ->orderBy('m.type', 'ASC')
        ;

        return (new EntityResult($qb))->asString(); // @phpstan-ignore-line
    }

    public function metrics(Specification $specification): array
    {
        $condition = self::statusCondition($specification->toArray()['status']);
        $queryBuilder = $this->queryBuilderFor($specification, order: false, filterStatus: false);
        $queryBuilder->select(
            'COUNT(m.finishedAt) - COUNT(m.failureType) AS success_count',
            'COUNT(m.failureType) AS failure_count',
            self::averageExpression('m.waitTime', $condition).' AS average_wait_time',
            self::averageExpression('m.handleTime', $condition).' AS average_handling_time',
        );
        $query = $queryBuilder->getQuery();
        /**
         * @var array{
         *     success_count: int|numeric-string,
         *     failure_count: int|numeric-string,
         *     average_wait_time: int|float|numeric-string|null,
         *     average_handling_time: int|float|numeric-string|null,
         * } $row
         */
        $row = $query->getSingleResult();

        return [
            'success_count' => (int) $row['success_count'],
            'failure_count' => (int) $row['failure_count'],
            'average_wait_time' => (int) $row['average_wait_time'],
            'average_handling_time' => (int) $row['average_handling_time'],
        ];
    }

    private function om(): ObjectManager
    {
        return $this->registry->getManagerForClass($this->entityClass) ?? throw new \LogicException(\sprintf('No ObjectManager for "%s".', $this->entityClass));
    }

    /**
     * @return EntityRepository<T>
     */
    private function repository(): EntityRepository
    {
        if (isset($this->repository)) {
            return $this->repository;
        }

        $repository = $this->registry->getRepository($this->entityClass);

        if (!$repository instanceof EntityRepository) {
            throw new \LogicException('Only the ORM is currently supported.');
        }

        return $this->repository = $repository;
    }

    private function queryBuilderFor(Specification $specification, bool $order = true, bool $filterStatus = true): QueryBuilder
    {
        [$from, $to, $status, $messageType, $transport, $tags, $notTags, $sort, $runId] = \array_values($specification->toArray());

        $qb = $this->repository()->createQueryBuilder('m');

        if ($from) {
            $qb->andWhere('m.finishedAt >= :from')->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('m.finishedAt <= :to')->setParameter('to', $to);
        }

        if ($messageType) {
            $qb->andWhere('m.type = :class')->setParameter('class', $messageType);
        }

        if ($transport) {
            $qb->andWhere('m.transport = :transport')->setParameter('transport', $transport);
        }

        if ($runId) {
            $qb->andWhere('m.runId = :run_id')->setParameter('run_id', $runId);
        }

        if ($filterStatus && null !== ($condition = self::statusCondition($status))) {
            $qb->andWhere($condition);
        }

        foreach ($tags as $i => $tag) {
            $qb->andWhere('m.tags LIKE :tag'.$i)->setParameter('tag'.$i, '%'.$tag.'%');
        }

        foreach ($notTags as $i => $notTag) {
            $expr = [] === $tags
                ? $qb->expr()->orX(
                    $qb->expr()->isNull('m.tags'),
                    $qb->expr()->notLike('m.tags', ':not_tag'.$i),
                )
                : 'm.tags NOT LIKE :not_tag'.$i;

            $qb->andWhere($expr)->setParameter('not_tag'.$i, '%'.$notTag.'%');
        }

        if ($order) {
            $qb->orderBy('m.finishedAt', \mb_strtoupper($sort));
        }

        return $qb;
    }

    /** @param Specification::SUCCESS|Specification::FAILED|null $status */
    private static function statusCondition(?string $status): ?string
    {
        return match ($status) {
            Specification::SUCCESS => 'm.failureType IS NULL',
            Specification::FAILED => 'm.failureType IS NOT NULL',
            null => null,
        };
    }

    private static function averageExpression(string $field, ?string $condition): string
    {
        if (null === $condition) {
            return "AVG({$field})";
        }

        // DQL requires an expression for ELSE and rejects a bare NULL.
        return "AVG(CASE WHEN {$condition} THEN {$field} ELSE NULLIF(1, 1) END)";
    }
}
