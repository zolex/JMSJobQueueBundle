<?php

namespace JMS\JobQueueBundle\Entity\Listener;

use ArrayIterator;
use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\ClosureExpressionVisitor;
use Doctrine\Common\Collections\Selectable;
use Doctrine\Persistence\ManagerRegistry;
use JMS\JobQueueBundle\Entity\Job;

/**
 * Collection for persistent related entities.
 *
 * We do not support all of Doctrine's built-in features.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class PersistentRelatedEntitiesCollection implements Collection, Selectable
{
    private ManagerRegistry $registry;
    private Job $job;
    private ?array $entities = null;

    public function __construct(ManagerRegistry $registry, Job $job)
    {
        $this->registry = $registry;
        $this->job = $job;
    }

    public function toArray(): array
    {
        $this->initialize();

        return $this->entities;
    }

    public function first()
    {
        $this->initialize();

        return reset($this->entities);
    }

    public function last()
    {
        $this->initialize();

        return end($this->entities);
    }

    public function key()
    {
        $this->initialize();

        return key($this->entities);
    }

    public function next()
    {
        $this->initialize();

        return next($this->entities);
    }

    public function current()
    {
        $this->initialize();

        return current($this->entities);
    }

    public function remove($key): ?object
    {
        throw new \LogicException('remove() is not supported.');
    }

    public function removeElement(mixed $element): bool
    {
        throw new \LogicException('removeElement() is not supported.');
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return $this->containsKey($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Adding new related entities is not supported after initial creation.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('unset() is not supported.');
    }

    public function containsKey(string|int $key): bool
    {
        $this->initialize();

        return isset($this->entities[$key]);
    }

    public function contains(mixed $element): bool
    {
        $this->initialize();

        return in_array($element, $this->entities, true);
    }

    public function exists(Closure $p): bool
    {
        $this->initialize();

        foreach ($this->entities as $key => $element) {
            if ($p($key, $element)) {
                return true;
            }
        }

        return false;
    }

    public function indexOf(mixed $element)
    {
        $this->initialize();

        return array_search($element, $this->entities, true);
    }

    public function get($key)
    {
        $this->initialize();

        return $this->entities[$key] ?? null;
    }

    public function getKeys(): array
    {
        $this->initialize();

        return array_keys($this->entities);
    }

    public function getValues(): array
    {
        $this->initialize();

        return array_values($this->entities);
    }

    public function count(): int
    {
        $this->initialize();

        return count($this->entities);
    }

    public function set($key, $value): void
    {
        throw new \LogicException('set() is not supported.');
    }

    public function add($value): bool
    {
        throw new \LogicException('Adding new entities is not supported after creation.');
    }

    /**
     * Note: This is preferable over count() == 0.
     */
    public function isEmpty(): bool
    {
        $this->initialize();

        return !$this->entities;
    }

    public function getIterator(): ArrayIterator
    {
        $this->initialize();

        return new ArrayIterator($this->entities);
    }

    public function map(Closure $func)
    {
        $this->initialize();

        return new ArrayCollection(array_map($func, $this->entities));
    }

    public function filter(Closure $p)
    {
        $this->initialize();

        return new ArrayCollection(array_filter($this->entities, $p));
    }

    public function forAll(Closure $p): bool
    {
        $this->initialize();

        foreach ($this->entities as $key => $element) {
            if (!$p($key, $element)) {
                return false;
            }
        }

        return true;
    }

    public function partition(Closure $p)
    {
        $this->initialize();

        $coll1 = $coll2 = [];
        foreach ($this->entities as $key => $element) {
            if ($p($key, $element)) {
                $coll1[$key] = $element;
            } else {
                $coll2[$key] = $element;
            }
        }

        return [new ArrayCollection($coll1), new ArrayCollection($coll2)];
    }

    public function __toString(): string
    {
        return __CLASS__.'@'.spl_object_hash($this);
    }

    public function clear(): void
    {
        throw new \LogicException('clear() is not supported.');
    }

    public function slice(int $offset, int|null $length = null): array
    {
        $this->initialize();

        return array_slice($this->entities, $offset, $length, true);
    }

    public function matching(Criteria $criteria)
    {
        $this->initialize();

        $expr = $criteria->getWhereExpression();
        $filtered = $this->entities;

        if ($expr) {
            $visitor = new ClosureExpressionVisitor();
            $filter = $visitor->dispatch($expr);
            $filtered = array_filter($filtered, $filter);
        }

        if (null !== $orderings = $criteria->getOrderings()) {
            $next = null;
            foreach (array_reverse($orderings) as $field => $ordering) {
                $next = ClosureExpressionVisitor::sortByField($field, $ordering == 'DESC' ? -1 : 1, $next);
            }

            usort($filtered, $next);
        }

        $offset = $criteria->getFirstResult();
        $length = $criteria->getMaxResults();

        if ($offset || $length) {
            $filtered = array_slice($filtered, (int) $offset, $length);
        }

        return new ArrayCollection($filtered);
    }

    private function initialize(): void
    {
        if (null !== $this->entities) {
            return;
        }

        $con = $this->registry->getManagerForClass(Job::class)->getConnection();
        $entitiesPerClass = [];
        $count = 0;
        foreach ($con->query("SELECT related_class, related_id FROM jms_job_related_entities WHERE job_id = ".$this->job->getId()) as $data) {
            ++$count;
            $entitiesPerClass[$data['related_class']][] = json_decode($data['related_id'], true);
        }

        if (0 === $count) {
            $this->entities = [];

            return;
        }

        $entities = [];
        foreach ($entitiesPerClass as $className => $ids) {
            $em = $this->registry->getManagerForClass($className);
            $qb = $em->createQueryBuilder()
                ->select('e')->from($className, 'e');

            $i = 0;
            foreach ($ids as $id) {
                $expr = null;
                foreach ($id as $k => $v) {
                    if (null === $expr) {
                        $expr = $qb->expr()->eq('e.'.$k, '?'.(++$i));
                    } else {
                        $expr = $qb->expr()->andX($expr, $qb->expr()->eq('e.'.$k, '?'.(++$i)));
                    }

                    $qb->setParameter($i, $v);
                }

                $qb->orWhere($expr);
            }

            $entities = array_merge($entities, $qb->getQuery()->getResult());
        }

        $this->entities = $entities;
    }

    public function findFirst(Closure $p)
    {
        // TODO: Implement findFirst() method.
    }

    public function reduce(Closure $func, mixed $initial = null)
    {
        // TODO: Implement reduce() method.
    }
}
