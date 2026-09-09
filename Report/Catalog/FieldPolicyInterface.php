<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * The last word on whether one field of one entity may be reported.
 *
 * ## Why a per-entity port and not a longer deny list
 *
 * The catalogue ships a global deny list of property NAMES — `password`, `totpSecret` and the like
 * — and that list is a blunt instrument by construction: it applies to every entity, so a name
 * that is sensitive on one and innocuous on another can only be denied everywhere.
 *
 * ⚠️ **That bluntness is what kept a whole HR module out of reporting in one application.** The
 * field catalogue traverses toOne relations, so exposing a leave request would have made an
 * employee's national insurance number, personal e-mail and date of birth selectable through
 * `leaveRequest.employee.*`. The only available answer was to deny those names globally and then
 * not expose the entity at all — so the module that most needed reports had none.
 *
 * A per-entity policy is the tool that unblocks it: deny `Employee.socialSecurityNumber` without
 * denying `socialSecurityNumber` on entities that legitimately carry one, and without denying the
 * entity itself.
 *
 * ```php
 * final class HrFieldPolicy implements FieldPolicyInterface
 * {
 *     public function allows(string $entity, string $field, AclUserInterface $actor): bool
 *     {
 *         if (Employee::class !== $entity) {
 *             return true;
 *         }
 *
 *         return !\in_array($field, ['socialSecurityNumber', 'iban', 'birthDate'], true)
 *             || $this->permissions->isGranted($actor, 'hr:payroll:read');
 *     }
 * }
 * ```
 *
 * ⚠️ **A policy can only narrow.** It is consulted after the global deny list and after the entity
 * gate, so returning `true` never re-opens something those refused. Anything else would make a
 * project-side class able to widen a bundle-side guarantee.
 */
interface FieldPolicyInterface
{
    /**
     * @param class-string $entity the entity the field belongs to — NOT the report root, which is
     *                             why a traversed field is judged on its own owner
     * @param string       $field  the property name, without any path
     */
    public function allows(string $entity, string $field, AclUserInterface $actor): bool;
}
