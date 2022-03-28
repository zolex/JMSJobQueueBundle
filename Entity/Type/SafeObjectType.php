<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Doctrine\DBAL\Types\ObjectType;

class SafeObjectType extends ObjectType
{
    public function getSQLDeclaration(array $fieldDeclaration, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($fieldDeclaration);
    }

    public function getName(): string
    {
        return 'jms_job_safe_object';
    }
}