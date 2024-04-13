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

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Messenger\Envelope;
use Zenstruck\Collection;
use Zenstruck\Collection\Doctrine\ORM\EntityResult;
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
    public function __construct(private Registry $registry, private string $entityClass)
    {
    }

    public function find(mixed $id): ?ProcessedMessage
    {
        return $this->repository()->find($id);
    }

    public function filter(Specification $specification): Collection
    {
        return new EntityResult($this->queryBuilderFor($specification));
    }

    public function purge(Specification $specification): int
    {
        return $this->queryBuilderFor($specification, order: false)->delete()->getQuery()->execute();
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

        return (new EntityResult($qb))->asString();
    }

    public function averageWaitTime(Specification $specification): ?float
    {
        $qb = $this
            ->queryBuilderFor($specification)
            ->select('AVG(m.receivedAt - m.dispatchedAt)')
        ;

        return (new EntityResult($qb))->asFloat()->first();
    }

    public function averageHandlingTime(Specification $specification): ?float
    {
        $qb = $this
            ->queryBuilderFor($specification)
            ->select('AVG(m.finishedAt - m.receivedAt)')
        ;

        return (new EntityResult($qb))->asFloat()->first();
    }

    public function count(Specification $specification): int
    {
        $qb = $this
            ->queryBuilderFor($specification)
            ->select('COUNT(m.finishedAt)')
        ;

        return (new EntityResult($qb))->asInt()->first(0);
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

    private function queryBuilderFor(Specification $specification, bool $order = true): QueryBuilder
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

        match ($status) {
            Specification::SUCCESS => $qb->andWhere('m.failureType IS NULL'),
            Specification::FAILED => $qb->andWhere('m.failureType IS NOT NULL'),
            null => null,
        };

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
}
