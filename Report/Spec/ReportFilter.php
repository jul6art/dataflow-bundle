<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

/**
 * One filter of a report: a field path, an operator, and up to two values.
 *
 * ⚠️ The operator is one of {@see FilterOperator}, and it is an enum rather than a string on
 * purpose: the runner used to `switch` on a raw string and `throw` on the default branch, so an
 * unknown operator was a runtime failure on a saved report rather than a rejection at the door.
 */
final readonly class ReportFilter
{
    /**
     * @param non-empty-string $path
     */
    public function __construct(
        public string $path,
        public FilterOperator $operator,
        public mixed $value = null,
        public mixed $secondValue = null,
    ) {
    }
}
