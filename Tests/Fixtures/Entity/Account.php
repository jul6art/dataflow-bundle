<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Depth 2 from the invoice, and the carrier of a globally denied column name.
 */
#[ORM\Entity]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $label = '';

    /**
     * ⚠️ Never reportable, whatever the entity, whatever the permission.
     */
    #[ORM\Column(length: 255)]
    public string $password = '';
}
