<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
use Jul6Art\DataflowBundle\Report\Format\ColumnFormat;
use Jul6Art\DataflowBundle\Report\Format\ColumnFormatter;
use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Report\Spec\ReportColumn;
use Jul6Art\DataflowBundle\Report\Spec\ReportFilter;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpec;
use Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain;

/**
 * Turns a {@see ReportSpec} into a Doctrine query and streams its rows.
 *
 * ## What it builds
 *
 * One `leftJoin` per relation crossed, cached by canonical path so that `customer.name` and
 * `customer.account.label` share the join on `customer`, and so that a filter reuses the join a
 * column already made. The SELECT is scalar — no entity is hydrated, so nothing enters the unit of
 * work and a fifty-thousand-row export does not become fifty thousand managed objects.
 *
 * ## The two gates, and where they sit
 *
 * ⚠️ The entity gate runs once, before anything is built; the field gate runs once per path,
 * against a memoised catalogue. Both are re-checked HERE rather than trusted from the screen,
 * because a crafted payload naming `order.organization.users.password` reaches this method without
 * passing any dropdown.
 *
 * ⚠️ **Filter paths are checked too, not only column paths.** A filter never appears in the output,
 * so it looks harmless — but it lands in the WHERE clause, and a filter on a forbidden field leaks
 * that field by which rows come back.
 *
 * ## Tenant scoping is the caller's, and deliberately so
 *
 * ⚠️ This bundle does not know what a tenant is. An application scopes its rows the way it already
 * does — a Doctrine filter, a discriminator, nothing at all if it is single-tenant — by passing a
 * `$scope` closure that receives the root alias and the query builder. Inventing an
 * `organization` column here would work for exactly one of the three applications this bundle
 * serves, and would silently return everything for the other two.
 *
 * ## A column's own format runs before the transformer chain
 *
 * ⚠️ {@see \Jul6Art\DataflowBundle\Report\Spec\ReportColumn::$format} (lot 2.6), when set, is
 * applied to that column's raw value BEFORE `$transformers->transformRow()` sees the row. A column
 * with no format is untouched — {@see \Jul6Art\DataflowBundle\Report\Format\ColumnFormatter::format()}
 * returns it unchanged — so every report built before this existed renders exactly as it did.
 */
final readonly class ReportRunner
{
    public const int DEFAULT_LIMIT = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityCatalog $entities,
        private FieldCatalog $fields,
        private ValueTransformerChain $transformers = new ValueTransformerChain(),
        private ColumnFormatter $columnFormatter = new ColumnFormatter(),
    ) {
    }

    /**
     * @param (\Closure(QueryBuilder, string): void)|null $scope applies the application's own row
     *                                                           scoping; receives the query builder
     *                                                           and the root alias
     *
     * @throws \DomainException when the entity or one of the paths is not reportable for this actor
     */
    public function run(
        ReportSpec $spec,
        AclUserInterface $actor,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        ?\Closure $scope = null,
    ): ReportResult {
        $this->entities->assertAllowed($actor, $spec->rootEntity);

        foreach ($spec->paths() as $path) {
            $this->fields->assertPathAllowed($spec->rootEntity, $actor, $path);
        }

        if (!$spec->hasColumns()) {
            return new ReportResult([], static fn (): \Generator => yield from []);
        }

        $query = $this->build($spec, $actor, $limit, $offset, $scope);

        $paths = \array_map(static fn (ReportColumn $c): string => $c->path, $spec->columns);
        $formats = \array_map(static fn (ReportColumn $c): ?ColumnFormat => $c->format, $spec->columns);
        $transformers = $this->transformers;
        $columnFormatter = $this->columnFormatter;

        return new ReportResult(
            $spec->header(),
            static function () use ($query, $paths, $formats, $transformers, $columnFormatter): \Generator {
                // ⚠️ `toIterable()`, not `getArrayResult()`. This is the whole fix: Doctrine yields
                // one row at a time from the driver's cursor, so peak memory is one row rather than
                // the result set — twice over, once the caller re-keyed it.
                foreach ($query->toIterable([], AbstractQuery::HYDRATE_SCALAR) as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }

                    $out = [];

                    foreach ($paths as $index => $path) {
                        // ⚠️ A column's OWN format runs first and produces a plain scalar; the
                        // transformer chain that follows only ever sees a DateTimeInterface / bool /
                        // BackedEnum on a column that did NOT opt in, so the two passes never
                        // compete for the same value.
                        $out[$path] = $columnFormatter->format($row['c'.$index] ?? null, $formats[$index]);
                    }

                    yield $transformers->transformRow($out);
                }
            },
        );
    }

    /**
     * @param (\Closure(QueryBuilder, string): void)|null $scope
     *
     * ⚠️ The generic parameters say `<null, mixed>` because that is what a `QueryBuilder` promises
     * before a hydration mode is chosen — the shape only becomes `array<string, mixed>` when the
     * caller asks for `HYDRATE_SCALAR`, which is why the loop narrows the row there rather than
     * claiming it here.
     *
     * @return \Doctrine\ORM\Query<null, mixed>
     */
    private function build(ReportSpec $spec, AclUserInterface $actor, int $limit, int $offset, ?\Closure $scope): \Doctrine\ORM\Query
    {
        $qb = $this->entityManager->createQueryBuilder()->from($spec->rootEntity, 'root');

        $scope?->__invoke($qb, 'root');

        // Canonical relation path → DQL alias, shared by columns and filters alike.
        $joins = ['' => 'root'];
        $aliases = 0;

        foreach ($spec->columns as $index => $column) {
            $expression = $this->resolve($qb, $column->path, $joins, $aliases);
            $qb->addSelect($expression.' AS c'.$index);

            if (null !== $column->sort) {
                $qb->addOrderBy($expression, 'asc' === $column->sort ? 'ASC' : 'DESC');
            }
        }

        $parameters = 0;

        foreach ($spec->filters as $filter) {
            $fieldType = $this->fields->typeOf($spec->rootEntity, $actor, $filter->path);
            $this->applyFilter($qb, $this->resolve($qb, $filter->path, $joins, $aliases), $filter, $parameters, $fieldType);
        }

        return $qb
            ->setFirstResult(\max(0, $offset))
            ->setMaxResults(\max(1, $limit))
            ->getQuery();
    }

    /**
     * Resolves `customer.account.label` to `a2.label`, creating each join once.
     *
     * ## Not every dotted segment is a relation
     *
     * ⚠️ **An `#[ORM\Embedded]` value object is projected, never joined.** Doctrine flattens it
     * onto its owner's table, so `root.billingAddress.postalCode` is a valid field path as it
     * stands — while joining it raises, at DQL parse time, « has no association named
     * billingAddress ». Both shapes look identical in a path: `customer.name` crosses a relation,
     * `billingAddress.city` does not, and only the class metadata can tell them apart.
     *
     * ⚠️ This broke a SHIPPED feature in the three consuming applications: a free report builder
     * takes its column palette from {@see FieldCatalog::listFor()}, which lists an embeddable's
     * sub-fields as ordinary scalars — so nothing stopped a user picking one, and the report
     * answered 500. Reported on cereezer 2026-09-14.
     *
     * ⚠️ The metadata is asked about the REMAINING path, not about the segment: `hasField()`
     * understands `billingAddress.postalCode` as one field, which is exactly the question worth
     * asking. Testing the segment alone would answer `false` and change nothing.
     *
     * @param array<string, string> $joins mutated: gains every alias it creates
     */
    private function resolve(QueryBuilder $qb, string $path, array &$joins, int &$aliases): string
    {
        $parts = \explode('.', $path);
        $scalar = \array_pop($parts);
        $alias = 'root';
        $cumulative = '';
        $class = $qb->getRootEntities()[0] ?? null;

        foreach ($parts as $index => $relation) {
            // ⚠️ The embedded case, checked BEFORE assuming a relation: what remains of the path
            // from here is a single field of the CURRENT class, so it projects directly and no
            // join — nor any further segment — has to be resolved.
            $remaining = \implode('.', \array_slice($parts, $index));

            if (null !== $class && $this->isField($class, $remaining.'.'.$scalar)) {
                return $alias.'.'.$remaining.'.'.$scalar;
            }

            $cumulative = '' === $cumulative ? $relation : $cumulative.'.'.$relation;

            if (isset($joins[$cumulative])) {
                $alias = $joins[$cumulative];
                $class = $this->targetOf($class, $relation);

                continue;
            }

            ++$aliases;
            $joins[$cumulative] = 'a'.$aliases;
            // ⚠️ `leftJoin` and not `join`: an inner join would DROP every row whose relation is
            // null, so adding an optional column to a report would silently shrink it.
            $qb->leftJoin($alias.'.'.$relation, $joins[$cumulative]);
            $alias = $joins[$cumulative];
            $class = $this->targetOf($class, $relation);
        }

        return $alias.'.'.$scalar;
    }

    /**
     * Is `$path` a single field of `$class` — an ordinary column, or an embedded one?
     *
     * ⚠️ Doctrine registers an embeddable's columns under their DOTTED name
     * (`billingAddress.postalCode`), which is why one `hasField()` answers for both shapes.
     *
     * @param class-string $class
     */
    private function isField(string $class, string $path): bool
    {
        try {
            return $this->entityManager->getClassMetadata($class)->hasField($path);
        } catch (\Throwable) {
            // A class the metadata factory does not know is not a reason to fail here: the caller
            // falls back to the relation branch, which reports the real problem in its own terms.
            return false;
        }
    }

    /**
     * The class on the far side of `$relation`, or `null` when it cannot be determined.
     *
     * @param class-string|null $class
     *
     * @return class-string|null
     */
    private function targetOf(?string $class, string $relation): ?string
    {
        if (null === $class) {
            return null;
        }

        try {
            $metadata = $this->entityManager->getClassMetadata($class);

            return $metadata->hasAssociation($relation)
                ? $metadata->getAssociationTargetClass($relation)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyFilter(QueryBuilder $qb, string $expression, ReportFilter $filter, int &$parameters, ?string $fieldType): void
    {
        // ⚠️ A valueless operator binds nothing. The enum's arity is the single place that decides,
        // so a stale payload cannot bind a parameter the DQL never names.
        if (0 === $filter->operator->arity()) {
            $qb->andWhere(\sprintf(
                '%s IS %sNULL',
                $expression,
                FilterOperator::IsNotNull === $filter->operator ? 'NOT ' : '',
            ));

            return;
        }

        $name = 'p'.(++$parameters);
        $value = $this->castForField($filter->value, $fieldType);

        if (FilterOperator::Between === $filter->operator) {
            $second = 'p'.(++$parameters);
            $qb->andWhere(\sprintf('%s BETWEEN :%s AND :%s', $expression, $name, $second))
                ->setParameter($name, $value)
                ->setParameter($second, $this->castForField($filter->secondValue, $fieldType));

            return;
        }

        if (FilterOperator::Contains === $filter->operator) {
            // ⚠️ A non-scalar reaching `like` is a payload defect, not a value: stringifying an
            // array would bind the word "Array" and match nothing, in silence.
            $needle = \is_scalar($filter->value) ? (string) $filter->value : '';

            $qb->andWhere(\sprintf('LOWER(%s) LIKE LOWER(:%s)', $expression, $name))
                ->setParameter($name, '%'.$needle.'%');

            return;
        }

        // ⚠️ A list operator needs its parentheses: `x IN :p` is a DQL syntax error, and
        // `x IN (:p)` with an array parameter is what Doctrine expands into a list.
        $qb->andWhere(\sprintf(
            $filter->operator->takesList() ? '%s %s (:%s)' : '%s %s :%s',
            $expression,
            $this->comparison($filter->operator),
            $name,
        ))->setParameter($name, $value);
    }

    /**
     * ⚠️ A filter's value ALWAYS arrives as a string — it is parsed off a query string, whether
     * typed by a user or read from a `static`/`api` datatable option (`"true"` / `"false"`). Bound
     * as-is against a Doctrine `boolean` column, `root.isActive = :p1` compares the STORED boolean
     * to the literal text — which a weakly-typed driver (SQLite) never matches, either way, and a
     * strict one rejects outright: found on the first `static` filter wired through a real export,
     * silently rendering zero rows instead of the expected count.
     *
     * ⚠️ Scoped to `boolean` alone, not attempted generically for every Doctrine type: an `integer`
     * or `decimal` column already round-trips a numeric STRING correctly through most drivers' own
     * parameter binding, and guessing a cast for every type this bundle does not exercise would
     * trade one silent wrong answer for another, less understood one.
     */
    private function castForField(mixed $value, ?string $fieldType): mixed
    {
        if ('boolean' !== $fieldType) {
            return $value;
        }

        if (\is_array($value)) {
            return \array_map(
                static fn (mixed $v): mixed => \is_string($v) ? \filter_var($v, \FILTER_VALIDATE_BOOLEAN) : $v,
                $value,
            );
        }

        return \is_string($value) ? \filter_var($value, \FILTER_VALIDATE_BOOLEAN) : $value;
    }

    /**
     * ⚠️ `match` without a default arm, so adding a case to {@see FilterOperator} without teaching
     * the runner about it is a **compile-time** concern rather than an unhandled branch at run
     * time. The version this replaces had a `default: throw`, which turned a forgotten operator
     * into a failure in front of whoever opened the report.
     */
    private function comparison(FilterOperator $operator): string
    {
        return match ($operator) {
            FilterOperator::Equals => '=',
            FilterOperator::NotEquals => '<>',
            FilterOperator::GreaterThan => '>',
            FilterOperator::GreaterThanOrEqual => '>=',
            FilterOperator::LessThan => '<',
            FilterOperator::LessThanOrEqual => '<=',
            FilterOperator::In => 'IN',
            FilterOperator::NotIn => 'NOT IN',
            FilterOperator::Contains,
            FilterOperator::Between,
            FilterOperator::IsNull,
            FilterOperator::IsNotNull => throw new \LogicException('Handled before this point.'),
        };
    }
}
