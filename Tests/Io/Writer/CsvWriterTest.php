<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Writer;

use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The CSV writer.
 *
 * ⚠️ The assertion that matters most here is {@see self::testRowsAreConsumedLazily()}: it asserts
 * that the first bytes reach the caller **before** the last row has been produced. That property is
 * the whole reason this layer exists, and the defect it replaces was invisible to a content
 * assertion — every row was correct, and two complete copies of the result set sat in memory.
 */
#[CoversClass(CsvWriter::class)]
final class CsvWriterTest extends TestCase
{
    public function testAWholeDocumentInTheDefaultDialect(): void
    {
        self::assertSame(
            "name,total\r\nDupont,100.00\r\n",
            $this->write(new CsvWriter(), ['name', 'total'], [['Dupont', '100.00']]),
        );
    }

    /**
     * ⚠️ The byte-order mark comes first or Excel FR reads the whole file as Latin-1.
     */
    public function testTheExcelFrDialectLeadsWithTheByteOrderMarkAndSeparatesOnSemicolons(): void
    {
        $out = $this->write(new CsvWriter(CsvDialect::excelFr()), ['prénom'], [['Émile']]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $out);
        self::assertSame("\xEF\xBB\xBFprénom\r\nÉmile\r\n", $out);
    }

    public function testTheTabSeparatedDialectUsesTabsAndBareLineFeeds(): void
    {
        self::assertSame(
            "JournalCode\tDebit\nVE\t139,76\n",
            $this->write(new CsvWriter(CsvDialect::tabSeparated()), ['JournalCode', 'Debit'], [['VE', '139,76']]),
        );
    }

    /**
     * ⚠️ The guarantee `escape: ''` buys, asserted as a ROUND TRIP rather than as a byte pattern.
     *
     * PHP's own default escape is the backslash, which is not CSV — the standard doubles a quote.
     * With the default, a field ending in a backslash escapes the closing quote and swallows the
     * separator, so the reader's spreadsheet loses a column boundary. What the fix guarantees is
     * therefore not a particular spelling but that **every field comes back**, and that is what a
     * reader has to be able to trust.
     *
     * (Asserting the exact bytes is what a first version of this test did, and it was wrong: PHP
     * quotes the trailing-backslash field, which is safer than the spelling I had predicted. A
     * round trip cannot be wrong about the property it checks.)
     */
    public function testAFieldEndingInABackslashDoesNotSwallowTheNextOne(): void
    {
        $out = $this->write(new CsvWriter(), [], [['ends with\\', 'say "hi"', 'last']]);

        self::assertSame(
            ['ends with\\', 'say "hi"', 'last'],
            str_getcsv(rtrim($out, "\r\n"), ',', '"', ''),
        );
    }

    public function testAFormulaIsNeutralisedInTheBodyAndInTheHeader(): void
    {
        $out = $this->write(new CsvWriter(), ['=1+1'], [['=HYPERLINK("x")']]);

        self::assertStringContainsString("'=1+1", $out);
        self::assertStringContainsString("'=HYPERLINK", $out);
    }

    /**
     * ⚠️ And the counter-proof, in the same layer: a negative amount is not a formula.
     */
    public function testANegativeAmountIsNotNeutralised(): void
    {
        self::assertSame("-100.00\r\n", $this->write(new CsvWriter(), [], [['-100.00']]));
    }

    public function testAnEmptyHeaderEmitsNoHeaderRow(): void
    {
        self::assertSame("a\r\n", $this->write(new CsvWriter(), [], [['a']]));
    }

    /**
     * The property this whole layer exists for.
     *
     * ⚠️ The state lives on an OBJECT, not in variables captured by reference. PHPStan at
     * `level: max` cannot follow a `use (&$x)` mutation and narrows the variable to its initial
     * value, so it declared both assertions impossible. An object also reads better: the recorder
     * says what it records.
     */
    public function testRowsAreConsumedLazily(): void
    {
        $recorder = new class {
            public int $produced = 0;

            /** @var list<string> */
            public array $chunks = [];

            public ?int $chunksWhenSecondRowWasAsked = null;
        };

        $rows = (static function () use ($recorder): \Generator {
            foreach ([['a'], ['b'], ['c']] as $row) {
                ++$recorder->produced;

                if (2 === $recorder->produced) {
                    $recorder->chunksWhenSecondRowWasAsked = \count($recorder->chunks);
                }

                yield $row;
            }
        })();

        new CsvWriter()->write(['h'], $rows, static function (string $chunk) use ($recorder): void {
            $recorder->chunks[] = $chunk;
        });

        self::assertSame(3, $recorder->produced, 'Every row was produced.');
        self::assertSame(
            2,
            $recorder->chunksWhenSecondRowWasAsked,
            'The header and the first row had already been emitted when the second row was asked for.',
        );
    }

    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     */
    private function write(CsvWriter $writer, array $header, iterable $rows): string
    {
        $out = '';

        $writer->write($header, $rows, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }
}
