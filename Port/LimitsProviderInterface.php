<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

use Jul6Art\AclBundle\Contract\AclTenantInterface;

/**
 * Where the ceilings come from.
 *
 * The bundle ships {@see ConfiguredLimitsProvider}, which answers the same {@see Limits} for
 * everyone from the bundle's configuration. An application whose ceilings are per tenant — a plan,
 * a quota, a settings table — binds this interface to its own service instead.
 *
 * ⚠️ **`$tenant` is nullable and the bundle never fills it in.** A single-tenant application has no
 * tenant object at all, and a bundle that required one would be unusable there. The caller passes
 * what it has.
 */
interface LimitsProviderInterface
{
    public function limitsFor(?AclTenantInterface $tenant = null): Limits;
}
