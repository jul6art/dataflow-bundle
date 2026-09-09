<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Spec;

use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpec;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The interpreter that turns a stored payload into a bounded spec.
 *
 * ⚠️ Every case here is a payload an application can really produce: a report saved by one version
 * and read by the next, a form that posts `in` as a comma-joined string, an `isNull` filter still
 * carrying the value the field had before the operator changed. None of them is hypothetical, and
 * each one used to be either a runtime exception or a silently empty report.
 */
#[CoversClass(ReportSpecInterpreter::class)]
#[CoversClass(ReportSpec::class)]
#[CoversClass(FilterOperator::class)]
final class ReportSpecInterpreterTest extends TestCase
{
    public function testAWholePayload(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [
                ['path' => 'id', 'label' => 'Reference', 'sort' => 'DESC'],
                ['path' => 'customer.name'],
            ],
            'filters' => [
                ['path' => 'status', 'op' => 'eq', 'value' => 'paid'],
            ],
        ]);

        self::assertSame(\DateTimeImmutable::class, $spec->rootEntity);
        self::assertSame(['Reference', 'customer.name'], $spec->header());
        self::assertSame('desc', $spec->columns[0]->sort);
        self::assertSame(FilterOperator::Equals, $spec->filters[0]->operator);
    }

    /**
     * ⚠️ A column with no label falls back to its path rather than to an empty header cell. An
     * unnamed column in a spreadsheet is unusable, and the path at least says what it is.
     */
    public function testAColumnWithoutALabelFallsBackToItsPath(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'total', 'label' => '   ']],
        ]);

        self::assertSame(['total'], $spec->header());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableRoots(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not a class' => ['App\\Does\\Not\\Exist'];
        yield 'not a string' => [42];
    }

    /**
     * ⚠️ The root is the ONE thing that throws. Without it there is nothing to run, so refusing is
     * the honest answer — where a broken column is dropped, because refusing a whole definition
     * over one of fourteen columns makes a stale report unopenable instead of repairable.
     */
    #[DataProvider('unusableRoots')]
    public function testAnUnusableRootEntityIsRefused(mixed $root): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportSpecInterpreter()->interpret(['entity' => $root, 'columns' => []]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableColumns(): iterable
    {
        yield 'no path' => [['label' => 'Total']];
        yield 'empty path' => [['path' => '   ']];
        yield 'path is not a string' => [['path' => ['a']]];
        yield 'path starts with a digit' => [['path' => '1field']];
        yield 'path carries a space' => [['path' => 'customer name']];
        yield 'path carries a quote' => [['path' => "id' OR 1=1"]];
        yield 'row is not an array' => ['customer.name'];
    }

    /**
     * ⚠️ A dropped column is the deliberate trade, and the shape check is also the first line of
     * defence: a path reaching the catalogues can only ever be a dotted identifier.
     */
    #[DataProvider('unusableColumns')]
    public function testAnUnusableColumnIsDroppedRatherThanFatal(mixed $column): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [$column, ['path' => 'id']],
        ]);

        self::assertSame(['id'], $spec->header(), 'The usable column survives, the other is gone.');
    }

    /**
     * ⚠️ An operator that no longer exists drops its filter instead of throwing. A report saved by
     * one version of an application and run by the next is the case, and the version this replaces
     * failed at run time, in front of whoever opened it.
     */
    public function testAnUnknownOperatorDropsItsFilter(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id']],
            'filters' => [
                ['path' => 'status', 'op' => 'sounds-like'],
                ['path' => 'total', 'op' => 'gte', 'value' => '10'],
            ],
        ]);

        self::assertCount(1, $spec->filters);
        self::assertSame(FilterOperator::GreaterThanOrEqual, $spec->filters[0]->operator);
    }

    /**
     * ⚠️ `in` gets a real list. `(array) "a,b"` yields one element containing a comma, which
     * matches no row and reports nothing — a silently empty report is worse than an error.
     */
    public function testACommaJoinedListOperatorIsSplit(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id']],
            'filters' => [['path' => 'status', 'op' => 'in', 'value' => 'paid, sent ,,draft']],
        ]);

        self::assertSame(['paid', 'sent', 'draft'], $spec->filters[0]->value);
    }

    /**
     * ⚠️ A valueless operator keeps no value. A stale payload where `isNull` still carries what
     * the field held before would otherwise bind a parameter the DQL never names, which Doctrine
     * reports as an error on a report that used to work.
     */
    public function testAValuelessOperatorDropsItsValue(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id']],
            'filters' => [['path' => 'paidAt', 'op' => 'isNull', 'value' => '2026-01-01', 'value2' => 'x']],
        ]);

        self::assertNull($spec->filters[0]->value);
        self::assertNull($spec->filters[0]->secondValue);
    }

    public function testBetweenKeepsBothValues(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id']],
            'filters' => [['path' => 'date', 'op' => 'between', 'value' => '2026-01-01', 'value2' => '2026-12-31']],
        ]);

        self::assertSame('2026-01-01', $spec->filters[0]->value);
        self::assertSame('2026-12-31', $spec->filters[0]->secondValue);
    }

    /**
     * ⚠️ The ceilings exist so a hand-written payload cannot make the runner build a query with
     * four hundred joins.
     */
    public function testTheCeilingsAreEnforced(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => array_fill(0, 200, ['path' => 'id']),
            'filters' => array_fill(0, 200, ['path' => 'id', 'op' => 'eq', 'value' => 1]),
        ]);

        self::assertCount(ReportSpecInterpreter::MAX_COLUMNS, $spec->columns);
        self::assertCount(ReportSpecInterpreter::MAX_FILTERS, $spec->filters);
    }

    public function testALongLabelIsTrimmedToTheCeiling(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id', 'label' => str_repeat('é', 400)]],
        ]);

        self::assertSame(ReportSpecInterpreter::MAX_LABEL_LENGTH, mb_strlen($spec->header()[0]));
    }

    /**
     * ⚠️ Filter paths are part of what the catalogues must check. A reader who only saw the column
     * loop would conclude a filter on a forbidden field is unchecked — and a filter reaches the
     * WHERE clause, so it leaks by including or excluding rows even when the field is never shown.
     */
    public function testEveryPathToCheckIncludesTheFiltersAndIsDeduplicated(): void
    {
        $spec = new ReportSpecInterpreter()->interpret([
            'entity' => \DateTimeImmutable::class,
            'columns' => [['path' => 'id'], ['path' => 'total']],
            'filters' => [['path' => 'total', 'op' => 'gt', 'value' => 1], ['path' => 'secret', 'op' => 'isNull']],
        ]);

        self::assertSame(['id', 'total', 'secret'], $spec->paths());
    }

    public function testASpecWithoutColumnsSaysSo(): void
    {
        $spec = new ReportSpecInterpreter()->interpret(['entity' => \DateTimeImmutable::class, 'columns' => []]);

        self::assertFalse($spec->hasColumns());
        self::assertSame([], $spec->header());
    }
}
