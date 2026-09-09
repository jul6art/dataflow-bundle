<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The many side. ⚠️ It must NEVER be traversed by the field catalogue: one invoice with four lines
 * would return four rows, and an export of a thousand invoices would silently multiply.
 */
#[ORM\Entity]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $designation = '';

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    public ?Invoice $invoice = null;
}
