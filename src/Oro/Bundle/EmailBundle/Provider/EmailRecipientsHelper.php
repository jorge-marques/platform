<?php

namespace Oro\Bundle\EmailBundle\Provider;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

use Oro\Bundle\EmailBundle\Entity\Repository\EmailAwareRepository;
use Oro\Bundle\EmailBundle\Model\CategorizedRecipient;
use Oro\Bundle\EmailBundle\Model\EmailRecipientsProviderArgs;
use Oro\Bundle\EmailBundle\Model\Recipient;
use Oro\Bundle\EmailBundle\Model\RecipientEntity;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\LocaleBundle\DQL\DQLNameFormatter;
use Oro\Bundle\LocaleBundle\Formatter\NameFormatter;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\EmailBundle\Entity\Provider\EmailOwnerProvider;
use Oro\Bundle\EmailBundle\Tools\EmailAddressHelper;

class EmailRecipientsHelper
{
    const ORGANIZATION_PROPERTY = 'organization';

     /** @var AclHelper */
    protected $aclHelper;

    /** @var DQLNameFormatter */
    private $dqlNameFormatter;

    /** @var NameFormatter */
    protected $nameFormatter;

    /** @var ConfigManager */
    protected $configManager;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var PropertyAccessor*/
    protected $propertyAccessor;

    /** @var EmailOwnerProvider */
    protected $emailOwnerProvider;

    /** @var Registry */
    protected $registry;

    /** @var EmailAddressHelper */
    protected $addressHelper;

    /**
     * @param AclHelper $aclHelper
     * @param DQLNameFormatter $dqlNameFormatter
     * @param NameFormatter $nameFormatter
     * @param ConfigManager $configManager
     * @param TranslatorInterface $translator
     * @param EmailOwnerProvider $emailOwnerProvider
     * @param Registry $registry
     * @param EmailAddressHelper $addressHelper
     */
    public function __construct(
        AclHelper $aclHelper,
        DQLNameFormatter $dqlNameFormatter,
        NameFormatter $nameFormatter,
        ConfigManager $configManager,
        TranslatorInterface $translator,
        EmailOwnerProvider $emailOwnerProvider,
        Registry $registry,
        EmailAddressHelper $addressHelper
    ) {
        $this->aclHelper = $aclHelper;
        $this->dqlNameFormatter = $dqlNameFormatter;
        $this->nameFormatter = $nameFormatter;
        $this->configManager = $configManager;
        $this->translator = $translator;
        $this->emailOwnerProvider = $emailOwnerProvider;
        $this->registry = $registry;
        $this->addressHelper = $addressHelper;
    }

    /**
     * @param object $object
     * @param ClassMetadata $objectMetadata
     *
     * @return RecipientEntity
     */
    public function createRecipientEntity($object, ClassMetadata $objectMetadata)
    {
        $identifiers = $objectMetadata->getIdentifierValues($object);
        if (count($identifiers) !== 1) {
            return null;
        }

        $organizationName = null;
        if ($this->getPropertyAccessor()->isReadable($object, static::ORGANIZATION_PROPERTY)) {
            $organization = $this->getPropertyAccessor()->getValue($object, static::ORGANIZATION_PROPERTY);
            if ($organization) {
                $organizationName = $organization->getName();
            }
        }

        return new RecipientEntity(
            $objectMetadata->name,
            reset($identifiers),
            $this->createRecipientEntityLabel($this->nameFormatter->format($object), $objectMetadata->name),
            $organizationName
        );
    }

    /**
     * @param string $name
     * @param Organization|null $organization
     *
     * @return Recipient
     */
    public function createRecipientFromEmail($name, Organization $organization = null)
    {
        $em = $this->registry->getManager();
        $email = $this->addressHelper->extractPureEmailAddress($name);
        $owner = $this->emailOwnerProvider->findEmailOwner($em, $email);
        if (!$owner || !$this->isObjectAllowedForOrganization($owner, $organization)) {
            return null;
        }

        $metadata = $em->getClassMetadata(ClassUtils::getClass($owner));

        return new Recipient(
            $email,
            $name,
            $this->createRecipientEntity($owner, $metadata)
        );
    }

    /**
     * @param Recipient $recipient
     *
     * @return array
     */
    public function createRecipientData(Recipient $recipient)
    {
        $data = ['key' => $recipient->getId()];
        if ($recipientEntity = $recipient->getEntity()) {
            $data = array_merge(
                $data,
                [
                    'contextText'  => $recipient->getEntity()->getLabel(),
                    'contextValue' => [
                        'entityClass' => $recipient->getEntity()->getClass(),
                        'entityId'    => $recipient->getEntity()->getId(),
                    ],
                    'organization' => $recipient->getEntity()->getOrganization(),
                ]
            );
        }

        return [
            'id' => $recipient->getId(),
            'text' => $recipient->getName(),
            'data' => json_encode($data),
        ];
    }

    /**
     * @param EmailRecipientsProviderArgs $args
     * @param EmailAwareRepository $repository
     * @param string $alias
     * @param string $entityClass
     *
     * @return Recipient[]
     */
    public function getRecipients(
        EmailRecipientsProviderArgs $args,
        EmailAwareRepository $repository,
        $alias,
        $entityClass
    ) {
        $fullNameQueryPart = $this->dqlNameFormatter->getFormattedNameDQL($alias, $entityClass);

        $excludedEmailNames = $args->getExcludedEmailNamesForEntity($entityClass);

        $primaryEmailsQb = $repository
            ->getPrimaryEmailsQb($fullNameQueryPart, $excludedEmailNames, $args->getQuery())
            ->setMaxResults($args->getLimit());

        $primaryEmailsResult = $this->getRestrictedResult($primaryEmailsQb, $args);
        $recipients = $this->recipientsFromResult($primaryEmailsResult, $entityClass);

        $limit = $args->getLimit() - count($recipients);

        if ($limit > 0) {
            $excludedEmailNames = array_merge(
                $excludedEmailNames,
                array_map(function (Recipient $recipient) {
                    return $recipient->getBasicNameWithOrganization();
                }, $recipients)
            );
            $secondaryEmailsQb = $repository
                ->getSecondaryEmailsQb($fullNameQueryPart, $excludedEmailNames, $args->getQuery())
                ->setMaxResults($limit);

            $secondaryEmailsResult = $this->getRestrictedResult($secondaryEmailsQb, $args);
            $recipients = array_merge($recipients, $this->recipientsFromResult($secondaryEmailsResult, $entityClass));
        }

        return $recipients;
    }

    /**
     * @param EmailRecipientsProviderArgs $args
     * @param Recipient[] $recipients
     *
     * @return array
     */
    public static function filterRecipients(EmailRecipientsProviderArgs $args, array $recipients)
    {
        return array_filter($recipients, function (Recipient $recipient) use ($args) {
            return !in_array($recipient->getIdentifier(), $args->getExcludedRecipientIdentifiers()) &&
                stripos($recipient->getName(), $args->getQuery()) !== false;
        });
    }

    /**
     * @param EmailRecipientsProviderArgs $args
     * @param object|null $object
     *
     * @return bool
     */
    public function isObjectAllowed(EmailRecipientsProviderArgs $args, $object = null)
    {
        return $this->isObjectAllowedForOrganization($object, $args->getOrganization());
    }

    /**
     * @param object|null $object
     * @param Organization|null $organization
     *
     * @return bool
     */
    public function isObjectAllowedForOrganization($object = null, Organization $organization = null)
    {
        if (!$organization ||
            !$object ||
            !$this->getPropertyAccessor()->isReadable($object, static::ORGANIZATION_PROPERTY)
        ) {
            return true;
        }

        $objectOrganization = $this->getPropertyAccessor()->getValue($object, static::ORGANIZATION_PROPERTY);
        if (!$organization) {
            return true;
        }

        return $objectOrganization === $organization;
    }

    /**
     * @param QueryBuilder $qb
     * @param EmailRecipientsProviderArgs $args
     *
     * @return array
     */
    protected function getRestrictedResult(QueryBuilder $qb, EmailRecipientsProviderArgs $args)
    {
        if ($args->getOrganization()) {
            $qb
                ->andWhere('o.id = :organization')
                ->setParameter('organization', $args->getOrganization());
        }

        return $this->aclHelper->apply($qb)->getResult();
    }

    /**
     * @param string $label
     * @param string $entityClass
     *
     * @return string
     */
    protected function createRecipientEntityLabel($label, $entityClass)
    {
        $label = trim($label);
        if ($classLabel = $this->getClassLabel($entityClass)) {
            $label .= ' (' . $classLabel . ')';
        }

        return $label;
    }

    /**
     * @param string $className
     * @return null|string
     */
    protected function getClassLabel($className)
    {
        if (!$this->configManager->hasConfig($className)) {
            return null;
        }
        $entityConfig = new EntityConfigId('entity', $className);
        $label        = $this->configManager->getConfig($entityConfig)->get('label');

        return $this->translator->trans($label);
    }

    /**
     * @param array $result
     * @param string $entityClass
     *
     * @return array
     */
    public function recipientsFromResult(array $result, $entityClass)
    {
        $emails = [];
        foreach ($result as $row) {
            $recipient = new CategorizedRecipient(
                $row['email'],
                sprintf('%s <%s>', $row['name'], $row['email']),
                new RecipientEntity(
                    $entityClass,
                    $row['entityId'],
                    $this->createRecipientEntityLabel($row['name'], $entityClass),
                    $row['organization']
                )
            );

            $emails[$recipient->getIdentifier()] = $recipient;
        }

        return $emails;
    }

    /**
     * @return PropertyAccessor
     */
    protected function getPropertyAccessor()
    {
        if (!$this->propertyAccessor) {
            $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        }

        return $this->propertyAccessor;
    }
}
