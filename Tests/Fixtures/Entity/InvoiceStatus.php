<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Fixtures\Entity;

/**
 * A backed enum on a fixture column, so the field catalogue meets one.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}
