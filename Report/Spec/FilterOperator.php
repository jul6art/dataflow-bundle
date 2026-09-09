<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

/**
 * The operators a report filter can use.
 *
 * ## Why an enum and not a string
 *
 * ⚠️ The runner this replaces took a raw string and `switch`ed on it, throwing on the default
 * branch. An unknown operator was therefore a **runtime failure on a saved report** — a report
 * saved by one version of the application, run by the next, failing in front of the user who
 * opened it. An enum moves the rejection to the door: {@see ReportSpecInterpreter} refuses the
 * payload, and a saved definition that cannot be interpreted cannot be run.
 *
 * ⚠️ **`isNull` and `isNotNull` take no value, and that is part of the type.** The interpreter
 * drops whatever value accompanies them rather than letting a stray parameter reach the query
 * builder, and {@see self::arity()} is what says so in one place instead of at every call site.
 */
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'like';
    case In = 'in';
    case NotIn = 'nin';
    case IsNull = 'isNull';
    case IsNotNull = 'isNotNull';
    case Between = 'between';

    /**
     * How many values the operator consumes: none, one, or two.
     */
    public function arity(): int
    {
        return match ($this) {
            self::IsNull, self::IsNotNull => 0,
            self::Between => 2,
            default => 1,
        };
    }

    /**
     * Whether the operator compares against a **list** rather than a scalar.
     *
     * ⚠️ This is what keeps `in`/`nin` from silently matching nothing. A saved report holding a
     * comma-joined string for `in` is a real shape — that is how a form posts it — and casting it
     * to `(array)` yields a one-element list containing the whole string, which matches no row and
     * reports no error.
     */
    public function takesList(): bool
    {
        return self::In === $this || self::NotIn === $this;
    }
}
