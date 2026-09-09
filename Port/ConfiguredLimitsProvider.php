<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

use Jul6Art\AclBundle\Contract\AclTenantInterface;

/**
 * The default: one set of ceilings, from `config/packages/dataflow.yaml`, the same for everyone.
 *
 * ⚠️ It ignores the tenant, deliberately and visibly. Two of this bundle's three target
 * applications have no tenant at all; making the default per-tenant would have meant inventing a
 * settings table in a bundle, which is the rule this ecosystem states as "a bundle interprets, a
 * project persists".
 */
final readonly class ConfiguredLimitsProvider implements LimitsProviderInterface
{
    public function __construct(
        private Limits $limits = new Limits(),
    ) {
    }

    #[\Override]
    public function limitsFor(?AclTenantInterface $tenant = null): Limits
    {
        return $this->limits;
    }
}
