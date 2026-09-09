<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * Which fields of a root entity a given actor may put in a report.
 *
 * Walks the Doctrine metadata: the root's own scalars, then the scalars of every toOne relation it
 * can reach within a depth.
 *
 * ## Four refusals, and each one is load-bearing
 *
 * ⚠️ **A toMany relation is never traversed.** One invoice with four lines would come back as four
 * rows, and an export of a thousand invoices would silently multiply. The user sees more rows than
 * there are records and has no way to tell why.
 *
 * ⚠️ **A globally denied property NAME is never offered**, whatever the entity and whatever the
 * permission. `password` reaching a spreadsheet once is once too often, and the list is code rather
 * than configuration for that reason.
 *
 * ⚠️ **A relation whose target is in the entity catalogue needs the actor to be allowed on that
 * target.** Without it, an actor holding `erp:order:read` and not `erp:customer:read` reads
 * `order.customer.email` — the permission on the root would silently grant the related entity. A
 * target that is *not* in the catalogue is traversed, deliberately: a plain look-up table is not a
 * reportable entity, and requiring one entry per referential would make the catalogue unusable.
 *
 * ⚠️ **A {@see FieldPolicyInterface} may narrow further, per entity.** The global list cannot
 * distinguish a name that is sensitive on one entity and innocuous on another, and that bluntness
 * is what kept an entire HR module out of reporting in one application.
 *
 * ## Memoised per root, actor and depth
 *
 * ⚠️ The version this replaces rebuilt the whole walk on **every** path check, and its runner
 * checked one path per column and one per filter. A thirteen-path report replayed the metadata
 * walk thirteen times, each replay re-asking the entity catalogue — itself unmemoised — for every
 * traversed relation. Nothing measured it, and the cost grew with the report rather than with the
 * data.
 */
final class FieldCatalog
{
    /**
     * Property names no entity may ever expose.
     *
     * ⚠️ Code, not configuration: a deny list that an application can shorten is a deny list that
     * will be shortened at 6pm on a Friday.
     *
     * @var list<string>
     */
    private const array GLOBAL_DENY = [
        'password',
        'plainPassword',
        'salt',
        'totpSecret',
        'apiToken',
        'apiTokenHash',
        'sessionToken',
        'rememberMeToken',
        'resetPasswordToken',
        'emailVerificationToken',
    ];

    public const int DEFAULT_MAX_DEPTH = 2;

    /** @var array<string, list<ReportField>> */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntityCatalog $entities,
        private readonly ?FieldPolicyInterface $policy = null,
    ) {
    }

    /**
     * @param class-string $rootFqcn
     *
     * @return list<ReportField>
     */
    public function listFor(string $rootFqcn, AclUserInterface $actor, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $key = $rootFqcn.'|'.($actor->getId() ?? 'anonymous').'|'.$maxDepth;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $fields = [];
        $this->collect($rootFqcn, '', '', $actor, $maxDepth, 0, $fields);

        return $this->memo[$key] = $fields;
    }

    /**
     * @param class-string $rootFqcn
     */
    public function isPathAllowed(
        string $rootFqcn,
        AclUserInterface $actor,
        string $path,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): bool {
        foreach ($this->listFor($rootFqcn, $actor, $maxDepth) as $field) {
            if ($field->path === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * The runtime guard, called before a path reaches a query builder.
     *
     * ⚠️ It exists because the screen is not the only way in: a crafted payload naming
     * `order.organization.users.password` has to be refused by the server, not merely absent from
     * a dropdown.
     *
     * @param class-string $rootFqcn
     *
     * @throws \DomainException
     */
    public function assertPathAllowed(
        string $rootFqcn,
        AclUserInterface $actor,
        string $path,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): void {
        if (!$this->isPathAllowed($rootFqcn, $actor, $path, $maxDepth)) {
            throw new \DomainException(\sprintf('"%s" is not a reportable field of %s.', $path, $rootFqcn));
        }
    }

    /**
     * @param list<ReportField> $fields
     */
    private function collect(
        string $fqcn,
        string $pathPrefix,
        string $labelPrefix,
        AclUserInterface $actor,
        int $maxDepth,
        int $depth,
        array &$fields,
    ): void {
        if (!\class_exists($fqcn)) {
            return;
        }

        /** @var class-string $classString */
        $classString = $fqcn;
        $meta = $this->entityManager->getClassMetadata($classString);

        foreach ($meta->getFieldNames() as $field) {
            if (!$this->allowsField($classString, $field, $actor)) {
                continue;
            }

            $path = '' === $pathPrefix ? $field : $pathPrefix.'.'.$field;

            $fields[] = new ReportField(
                $path,
                '' === $labelPrefix ? $field : $labelPrefix.' › '.$field,
                (string) ($meta->getTypeOfField($field) ?? 'string'),
                '' !== $pathPrefix,
            );
        }

        if ($depth >= $maxDepth) {
            return;
        }

        foreach ($meta->getAssociationMappings() as $name => $association) {
            $target = $this->traversableTarget($meta, $name, $association, $actor);

            if (null === $target) {
                continue;
            }

            $this->collect(
                $target,
                '' === $pathPrefix ? $name : $pathPrefix.'.'.$name,
                '' === $labelPrefix ? $name : $labelPrefix.' › '.$name,
                $actor,
                $maxDepth,
                $depth + 1,
                $fields,
            );
        }
    }

    /**
     * @param ClassMetadata<object> $meta
     * @param array<string, mixed>|object $association
     *
     * @return class-string|null the entity to descend into, or null when the relation must not be
     *                           traversed
     */
    private function traversableTarget(ClassMetadata $meta, string $name, array|object $association, AclUserInterface $actor): ?string
    {
        // ⚠️ Asked of the metadata rather than read off the mapping array: Doctrine 3 hands back
        // objects for some drivers and arrays for others, and reading `$association['type']` works
        // on one and silently yields null on the other — which would traverse every toMany.
        if (!$meta->isSingleValuedAssociation($name)) {
            return null;
        }

        $target = $meta->getAssociationTargetClass($name);

        if (!\class_exists($target)) {
            return null;
        }

        // A target the catalogue knows about is gated; one it does not is a referential, and
        // requiring an entry per look-up table would make the catalogue unusable.
        if (null !== $this->entities->metaFor($target) && !$this->entities->isAllowed($actor, $target)) {
            return null;
        }

        return $target;
    }

    /**
     * @param class-string $entity
     */
    private function allowsField(string $entity, string $field, AclUserInterface $actor): bool
    {
        if (\in_array($field, self::GLOBAL_DENY, true)) {
            return false;
        }

        // ⚠️ The policy is consulted LAST, so it can only narrow: returning true here never
        // re-opens what the global list already refused.
        return null === $this->policy || $this->policy->allows($entity, $field, $actor);
    }
}
