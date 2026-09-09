<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Depth 1 from the invoice, and an entity the catalogue can gate.
 */
#[ORM\Entity]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = '';

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    public ?Account $account = null;
}
