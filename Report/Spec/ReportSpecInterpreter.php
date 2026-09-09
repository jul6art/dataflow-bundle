<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

/**
 * Turns a raw payload — a decoded JSON column, a form submission — into a bounded {@see ReportSpec}.
 *
 * ## What it guarantees, and what it deliberately does not
 *
 * It guarantees a value that is **well formed and bounded**: an existing class as root, no unknown
 * operator, no column without a path, no more columns than the ceiling allows, labels trimmed to a
 * length. It does **not** guarantee a *meaningful* one — whether `customer.name` is a field of the
 * root entity, and whether this actor may read it, are questions for the catalogues, against a
 * schema and a permission set, at run time.
 *
 * That split is the same one `datatable-bundle` arrived at for preferences, and it is worth saying
 * out loud because it looks like a gap: **the interpreter cannot know the vocabulary.** One
 * interpreter serves every report of every entity; the thing that knows which fields exist lives
 * behind the screen, not behind this call.
 *
 * ## Why it drops rather than throws, except at the root
 *
 * ⚠️ A column whose path is empty, or whose operator no longer exists, is **dropped**. A saved
 * report is edited over months by people and by successive versions of an application; refusing
 * the whole definition because one of fourteen columns lost its meaning would make a stale report
 * unopenable rather than repairable. The root entity is the exception: without it there is nothing
 * to run, so a missing or unknown root is an exception.
 *
 * ⚠️ **The consequence has to be said and not hidden:** a report can come back with fewer columns
 * than it was saved with, and nothing on the screen announces it. That is the same trade the
 * datatable preferences made — an unknown key is discarded, a new column is appended — and the
 * reason is identical: the alternative costs the user the whole layout.
 */
final readonly class ReportSpecInterpreter
{
    /**
     * Ceilings, not preferences. They exist so that a hand-written payload cannot make the runner
     * build a query with four hundred joins.
     */
    public const int MAX_COLUMNS = 50;
    public const int MAX_FILTERS = 25;
    public const int MAX_LABEL_LENGTH = 120;

    /**
     * @param array<array-key, mixed> $payload expects `entity`, `columns`, `filters`
     *
     * @throws \InvalidArgumentException when the root entity is missing or is not a loadable class
     */
    public function interpret(array $payload): ReportSpec
    {
        $root = $payload['entity'] ?? null;

        if (!\is_string($root) || '' === $root || !\class_exists($root)) {
            throw new \InvalidArgumentException(
                'A report needs an existing root entity; got '.\get_debug_type($root).'.',
            );
        }

        return new ReportSpec(
            $root,
            $this->columns($payload['columns'] ?? null),
            $this->filters($payload['filters'] ?? null),
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(mixed $raw): array
    {
        $columns = [];

        foreach ($this->rows($raw, self::MAX_COLUMNS) as $row) {
            $path = $this->path($row['path'] ?? null);

            if (null === $path) {
                continue;
            }

            $label = \is_string($row['label'] ?? null) ? \trim($row['label']) : '';

            $columns[] = new ReportColumn(
                $path,
                '' !== $label ? \mb_substr($label, 0, self::MAX_LABEL_LENGTH) : $path,
                $this->sort($row['sort'] ?? null),
            );
        }

        return $columns;
    }

    /**
     * @return list<ReportFilter>
     */
    private function filters(mixed $raw): array
    {
        $filters = [];

        foreach ($this->rows($raw, self::MAX_FILTERS) as $row) {
            $path = $this->path($row['path'] ?? null);
            $operator = \is_string($row['op'] ?? null) ? FilterOperator::tryFrom($row['op']) : null;

            if (null === $path || null === $operator) {
                continue;
            }

            // ⚠️ The arity decides what is kept. `isNull` carrying a value is a payload from an
            // older version of a form, and letting that value through would bind a parameter the
            // DQL never names — which Doctrine reports as an error on a report that used to work.
            $filters[] = new ReportFilter(
                $path,
                $operator,
                $operator->arity() >= 1 ? $this->value($operator, $row['value'] ?? null) : null,
                2 === $operator->arity() ? $row['value2'] ?? null : null,
            );
        }

        return $filters;
    }

    /**
     * ⚠️ A list operator gets a real list. A saved `in` filter holding `"a,b"` is a shape a form
     * produces, and `(array) "a,b"` yields `['a,b']` — one element containing a comma — which
     * matches no row and reports nothing. Splitting is the only reading that cannot silently
     * return an empty report.
     */
    private function value(FilterOperator $operator, mixed $raw): mixed
    {
        if (!$operator->takesList()) {
            return $raw;
        }

        if (\is_array($raw)) {
            return \array_values($raw);
        }

        if (\is_string($raw)) {
            return \array_values(\array_filter(\array_map('trim', \explode(',', $raw)), static fn (string $v): bool => '' !== $v));
        }

        return null === $raw ? [] : [$raw];
    }

    /**
     * @return non-empty-string|null
     */
    private function path(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        $path = \trim($raw);

        // ⚠️ The shape is checked here so the catalogues receive something that can only be a
        // dotted field path. It is NOT a permission check and it is not a schema check — a path
        // that passes this may still be refused by both.
        if (1 !== \preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * @return 'asc'|'desc'|null
     */
    private function sort(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        return match (\strtolower(\trim($raw))) {
            'asc' => 'asc',
            'desc' => 'desc',
            default => null,
        };
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(mixed $raw, int $ceiling): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $row) {
            if (\count($rows) >= $ceiling) {
                break;
            }

            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
