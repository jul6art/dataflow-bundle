<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Sink;

use Jul6Art\DataflowBundle\Import\Sink\CsvErrorSink;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvErrorSink::class)]
final class CsvErrorSinkTest extends TestCase
{
    /**
     * ⚠️ Dialect defaults to plain LF here, deliberately: {@see CsvDialect}'s own default is CRLF
     * (RFC 4180 is explicit about it), and mixing that with plain-string assertions would make every
     * expected literal below carry an invisible `\r`. The CRLF path gets its own dedicated test.
     */
    public function testTheHeaderIsWrittenImmediatelyEvenWithNoErrors(): void
    {
        $handle = $this->handle();
        new CsvErrorSink($handle, new CsvDialect(lineEnding: "\n"));

        self::assertSame("record,message\n", $this->contents($handle));
    }

    public function testARecordIsAppendedAsARow(): void
    {
        $handle = $this->handle();
        $sink = new CsvErrorSink($handle, new CsvDialect(lineEnding: "\n"));

        $sink->record(4, 'dataflow.import.error.duplicate');
        $sink->record(9, 'email: this value is not a valid email address.');

        self::assertSame(
            "record,message\n"
            ."4,dataflow.import.error.duplicate\n"
            .'9,"email: this value is not a valid email address."'."\n",
            $this->contents($handle),
        );
    }

    /**
     * ⚠️ A message can be a validator's rendering of a value the user typed. Without the guard, a
     * row like `1,=cmd|'/c calc'!A1` opens a shell prompt the moment someone opens the file in Excel.
     */
    public function testAFormulaLikeMessageIsNeutralised(): void
    {
        $handle = $this->handle();
        $sink = new CsvErrorSink($handle, new CsvDialect(lineEnding: "\n"));

        $sink->record(1, "=cmd|'/c calc'!A1");

        self::assertStringContainsString("'=cmd", $this->contents($handle));
    }

    public function testACustomHeaderIsHonoured(): void
    {
        $handle = $this->handle();
        new CsvErrorSink($handle, new CsvDialect(lineEnding: "\n"), ['ligne', 'erreur']);

        self::assertSame("ligne,erreur\n", $this->contents($handle));
    }

    /**
     * ⚠️ `fputcsv` always ends a row with `\n`; a dialect asking for CRLF — the default, RFC 4180 is
     * explicit about it — is honoured by seeking back and overwriting it, proven here on a REAL row,
     * not just the header, since the two take different code paths only in what they contain, never
     * in how the ending is fixed.
     */
    public function testTheDefaultDialectEndsEveryLineInCrlf(): void
    {
        $handle = $this->handle();
        $sink = new CsvErrorSink($handle);

        $sink->record(1, 'x');

        $contents = $this->contents($handle);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $contents));
        self::assertSame(2, substr_count($contents, "\r\n"));
    }

    public function testABomIsWrittenOnceWhenTheDialectAsksForOne(): void
    {
        $handle = $this->handle();
        $sink = new CsvErrorSink($handle, CsvDialect::excelFr());

        $sink->record(1, 'x');

        $contents = $this->contents($handle);
        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));
    }

    /**
     * @return resource
     */
    private function handle()
    {
        $handle = fopen('php://temp', 'w+');
        self::assertNotFalse($handle);

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function contents($handle): string
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        self::assertNotFalse($contents);

        return $contents;
    }
}
