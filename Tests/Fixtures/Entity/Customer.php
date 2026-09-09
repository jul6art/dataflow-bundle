<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Depth 1 from the invoice, and an entity the catalogue can gate.
 *
 * The `Assert` constraint is here for the import runner: a row the validator rejects has to land in
 * the report next to its record number instead of killing the batch's flush.
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
    #[Assert\Email]
    public ?string $email = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    public ?Account $account = null;
}
