<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

use Jul6Art\DataflowBundle\Report\Format\ColumnFormat;

/**
 * One column of a report: which field it reads, what it is called, how it sorts, and — optionally —
 * how it is rendered.
 *
 * ⚠️ `$label` is what a **user typed** in a report builder, which is why it is guarded like data
 * before it reaches a file. `$path` is a dotted field path (`customer.name`) the field catalogue has
 * to recognise; nothing here validates it, because a value object cannot know a schema.
 *
 * ⚠️ **`$format` is `null` by default, and `null` means "unchanged".** A column with no format goes
 * through {@see \Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain} exactly as it
 * always has — ISO for a date, the raw value for a number. Lot 2.6 added the field; it did not
 * change what an existing report, built before the field existed, produces.
 */
final readonly class ReportColumn
{
    /**
     * @param non-empty-string      $path
     * @param 'asc'|'desc'|null     $sort
     */
    public function __construct(
        public string $path,
        public string $label,
        public ?string $sort = null,
        public ?ColumnFormat $format = null,
    ) {
    }
}
