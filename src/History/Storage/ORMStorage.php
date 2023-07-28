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
        return new EntityResult($this->queryBuilderFor($specification, order: true));
    }

    public function purge(Specification $specification): int
    {
        return $this->queryBuilderFor($specification)->delete()->getQuery()->execute();
    }

    public function save(Envelope $envelope, ?\Throwable $exception = null): void
    {
        $om = $this->om();
        $object = new $this->entityClass($envelope, $exception);

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

    public function averageWaitTime(Specification $specification): ?float
    {
        return $this->queryBuilderFor($specification)
            ->select('AVG(m.receivedAt - m.dispatchedAt)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function averageHandlingTime(Specification $specification): ?float
    {
        return $this->queryBuilderFor($specification)
            ->select('AVG(m.handledAt - m.receivedAt)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function count(Specification $specification): int
    {
        return $this->queryBuilderFor($specification)
            ->select('COUNT(m.handledAt)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
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

    private function queryBuilderFor(Specification $specification, bool $order = false): QueryBuilder
    {
        [$from, $to, $status, $messageType, $transport, $tag, $notTag] = \array_values($specification->toArray());

        $qb = $this->repository()->createQueryBuilder('m');

        if ($from) {
            $qb->andWhere('m.handledAt >= :from')->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('m.handledAt <= :to')->setParameter('to', $to);
        }

        if ($messageType) {
            $qb->andWhere('m.type = :class')->setParameter('class', $messageType);
        }

        if ($transport) {
            $qb->andWhere('m.transport = :transport')->setParameter('transport', $transport);
        }

        match ($status) {
            Specification::SUCCESS => $qb->andWhere('m.failure IS NULL'),
            Specification::FAILED => $qb->andWhere('m.failure IS NOT NULL'),
            null => null,
        };

        if ($tag) {
            $qb->andWhere('m.tags LIKE :tag')->setParameter('tag', '%'.$tag.'%');
        }

        if ($notTag) {
            $qb->andWhere('m.tags NOT LIKE :not_tag')->setParameter('not_tag', '%'.$notTag.'%');
        }

        if ($order) {
            $qb->orderBy('m.handledAt', 'DESC');
        }

        return $qb;
    }
}
