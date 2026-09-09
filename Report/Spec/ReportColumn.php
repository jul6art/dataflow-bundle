<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

/**
 * One column of a report: which field it reads, what it is called, how it sorts.
 *
 * ⚠️ `$label` is what a **user typed** in a report builder, which is why it is guarded like data
 * before it reaches a file. `$path` is a dotted field path (`customer.name`) the field catalogue has
 * to recognise; nothing here validates it, because a value object cannot know a schema.
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
    ) {
    }
}
