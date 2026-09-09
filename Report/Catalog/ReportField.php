<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

/**
 * One field a report may select: its dotted path, a label, its Doctrine type.
 *
 * ⚠️ `$type` is the DOCTRINE type (`string`, `decimal`, `date_immutable`), not a PHP one. It is
 * what a caller needs to decide a filter widget or a column format — and it is the truth, where a
 * renderer chosen by hand is only what someone wrote. Eleven columns of one application carried the
 * wrong renderer for exactly that reason.
 */
final readonly class ReportField
{
    /**
     * ⚠️ `$path` is a plain `string`, not a `non-empty-string`, and the difference is deliberate:
     * it is built from Doctrine's field names, which static analysis cannot prove non-empty, and
     * the promise carried nothing here. {@see \Jul6Art\DataflowBundle\Report\Spec\ReportColumn}
     * keeps the stronger type, because there the interpreter genuinely guarantees it.
     *
     * @param string $type      the Doctrine field type
     * @param bool   $traversed whether the path crosses at least one relation
     */
    public function __construct(
        /** Dotted path from the root entity. */
        public string $path,
        public string $label,
        public string $type,
        public bool $traversed = false,
    ) {
    }
}
