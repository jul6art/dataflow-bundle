<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\DataflowBundle\Report\Catalog\EntityCatalog;
use Jul6Art\DataflowBundle\Report\Catalog\FieldCatalog;
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
 */
final readonly class ReportRunner
{
    public const int DEFAULT_LIMIT = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityCatalog $entities,
        private FieldCatalog $fields,
        private ValueTransformerChain $transformers = new ValueTransformerChain(),
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

        $query = $this->build($spec, $limit, $offset, $scope);

        $paths = \array_map(static fn (ReportColumn $c): string => $c->path, $spec->columns);
        $transformers = $this->transformers;

        return new ReportResult(
            $spec->header(),
            static function () use ($query, $paths, $transformers): \Generator {
                // ⚠️ `toIterable()`, not `getArrayResult()`. This is the whole fix: Doctrine yields
                // one row at a time from the driver's cursor, so peak memory is one row rather than
                // the result set — twice over, once the caller re-keyed it.
                foreach ($query->toIterable([], AbstractQuery::HYDRATE_SCALAR) as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }

                    $out = [];

                    foreach ($paths as $index => $path) {
                        $out[$path] = $row['c'.$index] ?? null;
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
    private function build(ReportSpec $spec, int $limit, int $offset, ?\Closure $scope): \Doctrine\ORM\Query
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
            $this->applyFilter($qb, $this->resolve($qb, $filter->path, $joins, $aliases), $filter, $parameters);
        }

        return $qb
            ->setFirstResult(\max(0, $offset))
            ->setMaxResults(\max(1, $limit))
            ->getQuery();
    }

    /**
     * Resolves `customer.account.label` to `a2.label`, creating each join once.
     *
     * @param array<string, string> $joins mutated: gains every alias it creates
     */
    private function resolve(QueryBuilder $qb, string $path, array &$joins, int &$aliases): string
    {
        $parts = \explode('.', $path);
        $scalar = \array_pop($parts);
        $alias = 'root';
        $cumulative = '';

        foreach ($parts as $relation) {
            $cumulative = '' === $cumulative ? $relation : $cumulative.'.'.$relation;

            if (isset($joins[$cumulative])) {
                $alias = $joins[$cumulative];

                continue;
            }

            ++$aliases;
            $joins[$cumulative] = 'a'.$aliases;
            // ⚠️ `leftJoin` and not `join`: an inner join would DROP every row whose relation is
            // null, so adding an optional column to a report would silently shrink it.
            $qb->leftJoin($alias.'.'.$relation, $joins[$cumulative]);
            $alias = $joins[$cumulative];
        }

        return $alias.'.'.$scalar;
    }

    private function applyFilter(QueryBuilder $qb, string $expression, ReportFilter $filter, int &$parameters): void
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

        if (FilterOperator::Between === $filter->operator) {
            $second = 'p'.(++$parameters);
            $qb->andWhere(\sprintf('%s BETWEEN :%s AND :%s', $expression, $name, $second))
                ->setParameter($name, $filter->value)
                ->setParameter($second, $filter->secondValue);

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
        ))->setParameter($name, $filter->value);
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
