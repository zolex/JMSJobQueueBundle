<?php

namespace JMS\JobQueueBundle\Entity\Listener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use JMS\JobQueueBundle\Entity\Job;

/**
 * Provides many-to-any association support for jobs.
 *
 * This listener only implements the minimal support for this feature. For
 * example, currently we do not support any modification of a collection after
 * its initial creation.
 *
 * @see http://docs.jboss.org/hibernate/orm/4.1/javadocs/org/hibernate/annotations/ManyToAny.html
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ManyToAnyListener
{
    private ManagerRegistry $registry;
    private \ReflectionProperty $ref;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
        $this->ref = new \ReflectionProperty(Job::class, 'relatedEntities');
        $this->ref->setAccessible(true);
    }

    public function postLoad(PostLoadEventArgs $event)
    {
        $entity = $event->getObject();
        if (!$entity instanceof Job) {
            return;
        }

        $this->ref->setValue($entity, new PersistentRelatedEntitiesCollection($this->registry, $entity));
    }

    public function preRemove(PreRemoveEventArgs $event)
    {
        $entity = $event->getObject();
        if (!$entity instanceof Job) {
            return;
        }

        $con = $event->getObjectManager()->getConnection();
        $con->executeStatement(
            "DELETE FROM jms_job_related_entities WHERE job_id = :id",
            [
                'id' => $entity->getId(),
            ]
        );
    }

    public function postPersist(PostPersistEventArgs $event)
    {
        $entity = $event->getObject();
        if (!$entity instanceof Job) {
            return;
        }

        $con = $event->getObjectManager()->getConnection();
        foreach ($this->ref->getValue($entity) as $relatedEntity) {
            $relClass = ClassUtils::getClass($relatedEntity);
            $relId = $this->registry->getManagerForClass($relClass)
                ->getMetadataFactory()
                ->getMetadataFor($relClass)
                ->getIdentifierValues($relatedEntity);
            asort($relId);

            if (!$relId) {
                throw new \RuntimeException('The identifier for the related entity "'.$relClass.'" was empty.');
            }

            $con->executeStatement(
                "INSERT INTO jms_job_related_entities (job_id, related_class, related_id) VALUES (:jobId, :relClass, :relId)",
                [
                    'jobId' => $entity->getId(),
                    'relClass' => $relClass,
                    'relId' => json_encode($relId),
                ]
            );
        }
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event)
    {
        $schema = $event->getSchema();

        // When using multiple entity managers ignore events that are triggered by other entity managers.
        if ($event->getEntityManager()->getMetadataFactory()->isTransient(Job::class)) {
            return;
        }

        $table = $schema->createTable('jms_job_related_entities');
        $table->addColumn('job_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('related_class', 'string', ['notnull' => true, 'length' => '150']);
        $table->addColumn('related_id', 'string', ['notnull' => true, 'length' => '100']);
        $table->setPrimaryKey(['job_id', 'related_class', 'related_id']);
        $table->addForeignKeyConstraint('jms_jobs', ['job_id'], ['id']);
    }
}
