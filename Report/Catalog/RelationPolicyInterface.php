<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * Which relations the field catalogue may walk, beyond what the entity catalogue already decides.
 *
 * ## Why this is separate from {@see FieldPolicyInterface}
 *
 * ⚠️ A field policy is asked about a SCALAR, so it cannot stop a traversal — and denying every
 * scalar of the target is not the same thing. The catalogue keeps descending, so
 * `organization.owner.email` is still offered even when every field of `Organization` is refused.
 * The first consumer of this bundle had deliberately removed `organization` from its reportable
 * relations, and the extraction put it back: not a cross-tenant leak — the rows stay scoped — but a
 * relation somebody had decided not to expose, exposed again, in silence.
 *
 * ## When the entity catalogue is not enough
 *
 * A relation whose target IS in the entity catalogue is already gated on that target's permission.
 * This interface is for the other case: a target the catalogue does not know, which is therefore
 * treated as a referential and walked freely — the right default (requiring one catalogue entry per
 * look-up table would make the catalogue unusable) and the wrong one for a handful of relations an
 * application has reasons to keep out of reports.
 *
 * ⚠️ Like a field policy, it can only NARROW: it runs after the catalogue's own decision, so
 * answering true never re-opens a relation the catalogue already refused.
 */
interface RelationPolicyInterface
{
    /**
     * @param class-string $entity   the class the relation is declared on
     * @param string       $relation the property name
     * @param class-string $target   what it points at
     */
    public function allows(string $entity, string $relation, string $target, AclUserInterface $actor): bool;
}
