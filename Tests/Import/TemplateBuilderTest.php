<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import;

use Jul6Art\DataflowBundle\Import\TemplateBuilder;
use Jul6Art\DataflowBundle\Tests\Fixtures\Import\CustomerRowMapper;
use Jul6Art\DataflowBundle\Tests\Fixtures\Import\TemplatableCustomerRowMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateBuilder::class)]
final class TemplateBuilderTest extends TestCase
{
    public function testAnEnumeratedFieldNamesItsAcceptedValuesInTheHeader(): void
    {
        $header = new TemplateBuilder()->header(new TemplatableCustomerRowMapper());

        self::assertSame(['name', 'email', 'status (active/inactive)'], $header);
    }

    /**
     * ⚠️ The columns are exactly {@see \Jul6Art\DataflowBundle\Import\RowMapperInterface::fields()},
     * in that order — the same columns a mapping screen would offer. A template with a different
     * shape would be a second contract, silently out of sync with the first.
     */
    public function testAPlainMapperGetsHeadersWithNoParentheses(): void
    {
        $header = new TemplateBuilder()->header(new CustomerRowMapper());

        self::assertSame(['name', 'email'], $header);
    }

    public function testTheExampleRowFillsInWhatTheMapperKnows(): void
    {
        $rows = new TemplateBuilder()->rows(new TemplatableCustomerRowMapper());

        self::assertSame([['Ada Lovelace', 'ada@example.test', '']], $rows);
    }

    /**
     * ⚠️ `status` has enumerated values but no example — the two are independent, and a field with
     * one and not the other is not a defect to paper over with a fabricated value.
     */
    public function testAFieldWithNoExampleIsBlankNotOmitted(): void
    {
        $rows = new TemplateBuilder()->rows(new TemplatableCustomerRowMapper());

        self::assertCount(3, $rows[0], 'Three fields, three cells — including the blank one.');
        self::assertSame('', $rows[0][2]);
    }

    /**
     * ⚠️ A plain `RowMapperInterface` still produces a usable template: the columns, and a row to
     * fill in. Refusing it — or silently rendering nothing — would make the richer interface
     * mandatory in practice, which the interface's own docblock promises it is not.
     */
    public function testAPlainMapperStillGetsOneBlankRowNotZero(): void
    {
        $rows = new TemplateBuilder()->rows(new CustomerRowMapper());

        self::assertSame([['', '']], $rows);
    }
}
