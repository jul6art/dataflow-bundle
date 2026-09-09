<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Writer;

use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;
use Jul6Art\DataflowBundle\Io\Writer\JsonWriter;
use Jul6Art\DataflowBundle\Io\Writer\XlsxWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three strings by which a writer is chosen and its response is labelled.
 *
 * ⚠️ Trivial-looking and load-bearing: `code()` is what a controller matches a `?format=` against,
 * `contentType()` is the header the browser decides with, and `fileExtension()` ends up in the
 * filename. Getting any of the three wrong produces a download that opens in the wrong program, or
 * a format the screen offers and the server cannot resolve.
 *
 * These assertions came from the application this was extracted from, and they are added HERE
 * before being deleted THERE — the rule this ecosystem arrived at after a project-side rounding
 * test was deleted and the behaviour it pinned was not in the bundle.
 */
#[CoversClass(CsvWriter::class)]
#[CoversClass(XlsxWriter::class)]
#[CoversClass(JsonWriter::class)]
final class WriterIdentifiersTest extends TestCase
{
    /**
     * @return iterable<string, array{TabularWriterInterface, string, string, string}>
     */
    public static function writers(): iterable
    {
        yield 'csv' => [new CsvWriter(), 'csv', 'csv', 'text/csv'];
        yield 'xlsx' => [new XlsxWriter(), 'xlsx', 'xlsx', 'spreadsheetml'];
        yield 'json' => [new JsonWriter(), 'json', 'json', 'application/json'];
    }

    #[DataProvider('writers')]
    public function testAWriterNamesItsFormatItsExtensionAndItsMediaType(
        TabularWriterInterface $writer,
        string $code,
        string $extension,
        string $mediaTypeFragment,
    ): void {
        self::assertSame($code, $writer->code());
        self::assertSame($extension, $writer->fileExtension());
        self::assertStringContainsString($mediaTypeFragment, $writer->contentType());
    }

    /**
     * ⚠️ A charset on the two text formats and none on the workbook. Without it Excel FR reads a
     * CSV as Latin-1 whatever byte-order mark it carries, and a browser guesses; declaring one on a
     * ZIP would be meaningless.
     */
    public function testTheTextFormatsDeclareTheirCharset(): void
    {
        self::assertStringContainsString('charset=UTF-8', new CsvWriter()->contentType());
        self::assertStringContainsString('charset=UTF-8', new JsonWriter()->contentType());
        self::assertStringNotContainsString('charset', new XlsxWriter()->contentType());
    }
}
