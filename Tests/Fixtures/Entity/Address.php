<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A value object, flattened onto its owner's table — NOT an association.
 *
 * ⚠️ This fixture exists for one reason: an embedded object is the only shape whose dotted path
 * (`billingAddress.postalCode`) looks exactly like a relation path (`customer.name`) and resolves
 * in a completely different way. Doctrine projects it directly — the columns live on the owner's
 * table — so joining it raises « has no association named billingAddress » at DQL parse time.
 *
 * Without such a fixture the runner could only ever be tested against real relations, which is
 * precisely why the defect reached three applications in production.
 */
#[ORM\Embeddable]
class Address
{
    #[ORM\Column(length: 12, nullable: true)]
    public ?string $postalCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $city = null;
}
