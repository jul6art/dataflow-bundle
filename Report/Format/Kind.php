<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Format;

/**
 * The four renderings {@see ColumnFormat} can ask for. Not exposed directly — construct a
 * {@see ColumnFormat} through its named constructors instead, which is what makes
 * `ColumnFormat::money(null)` uncompilable rather than a runtime surprise.
 */
enum Kind
{
    case Number;
    case Money;
    case Percent;
    case Date;
    case DateTime;
}
