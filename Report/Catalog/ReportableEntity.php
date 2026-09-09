<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

/**
 * What the catalogue knows about one reportable entity: how to name it, and the two gates it sits
 * behind.
 *
 * ⚠️ **`$feature` is nullable, and that is what makes the bundle usable outside a multi-tenant
 * SaaS.** Feature flags say what a tenant BOUGHT; permissions say what a person MAY DO. An
 * application with one product has no feature system at all, declares `null`, and only the
 * permission gate applies. Making the feature mandatory would have forced every single-product
 * consumer to invent a flag system to satisfy a signature.
 */
final readonly class ReportableEntity
{
    /**
     * @param string      $labelKey   translation key, in the application's own domain
     * @param string      $permission the permission code an actor needs to report on this entity
     * @param string|null $feature    the tenant feature that must be enabled, or null when the
     *                                application has no feature system
     */
    public function __construct(
        public string $labelKey,
        public string $permission,
        public ?string $feature = null,
    ) {
    }
}
