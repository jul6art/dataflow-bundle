<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The report root of the fixtures: scalars of several types, a toOne at depth 1, a toMany that must
 * be skipped, and a self-reference so a cycle can be proven to terminate.
 */
#[ORM\Entity]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30)]
    public string $number = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public string $total = '0.00';

    /**
     * ⚠️ Non-nullable property for a non-nullable column, and initialised in the constructor.
     * A `?DateTimeImmutable` here against a `nullable: false` column is the mismatch this
     * ecosystem forbids outright — an entity must never be able to raise an SQL error a form could
     * have caught — and PHPStan's Doctrine extension says so at level max.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public bool $paid = false;

    #[ORM\Column(length: 20, enumType: InvoiceStatus::class)]
    public InvoiceStatus $status = InvoiceStatus::Draft;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    public ?Customer $customer = null;

    /**
     * ⚠️ Self-reference: the catalogue must terminate on it by depth, not loop.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $creditedInvoice = null;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice')]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->issuedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }
}
