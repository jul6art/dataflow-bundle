<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Spec;

/**
 * What a report asks for: one root entity, its columns, its filters.
 *
 * ## The bundle interprets, the application persists
 *
 * ⚠️ This object is **not** an entity, and the bundle ships no table for it. That is the rule this
 * ecosystem settled once, for datatable preferences, and the reason is not doctrinal: the shape an
 * application already has for its user-owned data is the shape it should keep, and a bundle
 * shipping an entity would impose a migration on every consumer to own a table none of them named.
 *
 * An application therefore stores its saved reports however it likes — a JSON column, three
 * tables, a file — and hands the raw payload to {@see ReportSpecInterpreter}, which returns this.
 * The port for that is `Port\ReportDefinitionStoreInterface`.
 *
 * ## Immutable, and validated by construction only in shape
 *
 * ⚠️ A spec that exists is well-**formed**: a root class-string, at least the types right, no
 * unknown operator. It is not thereby **meaningful** — whether `customer.name` is a field of the
 * root entity, and whether this actor may read it, are questions only the catalogues can answer,
 * at run time, against a schema and a permission set. The same split as the datatable preference
 * blob: the interpreter guarantees a bounded, well-formed value, not a significant one.
 */
final readonly class ReportSpec
{
    /**
     * @param class-string        $rootEntity
     * @param list<ReportColumn>  $columns
     * @param list<ReportFilter>  $filters
     */
    public function __construct(
        public string $rootEntity,
        public array $columns,
        public array $filters = [],
    ) {
    }

    public function hasColumns(): bool
    {
        return [] !== $this->columns;
    }

    /**
     * The header row, in declaration order.
     *
     * @return list<string>
     */
    public function header(): array
    {
        return \array_map(static fn (ReportColumn $column): string => $column->label, $this->columns);
    }

    /**
     * The field paths every catalogue check has to cover: the columns' and the filters'.
     *
     * ⚠️ Filters are included, and forgetting them is the hole. The runner this replaces checked
     * both, but a reader who only looked at the column loop would have concluded that a filter on
     * a forbidden field was unchecked — and a filter reaches the WHERE clause, so it leaks by
     * inclusion or exclusion of rows even when the field is never displayed.
     *
     * @return list<non-empty-string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->columns as $column) {
            $paths[] = $column->path;
        }

        foreach ($this->filters as $filter) {
            $paths[] = $filter->path;
        }

        return \array_values(\array_unique($paths));
    }
}
