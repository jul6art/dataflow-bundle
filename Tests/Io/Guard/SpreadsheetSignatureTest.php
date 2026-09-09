<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Guard;

use Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpreadsheetSignature::class)]
final class SpreadsheetSignatureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function heads(): iterable
    {
        yield 'xlsx / ods / any ZIP' => ["PK\x03\x04\x14\x00\x06\x00", true];
        yield 'xls, the one that walked through the MIME list' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", true];
        yield 'a plain CSV' => ["name;email\n", false];
        yield 'a CSV with a byte-order mark' => ["\xEF\xBB\xBFname;email\n", false];
        yield 'a CSV whose first cell says PK' => ["PK,Ada\n", false];
        yield 'an empty file' => ['', false];
        yield 'three bytes of a ZIP header' => ["PK\x03", false];
        yield 'a ZIP marker that is not at the start' => ["x\x00PK\x03\x04", false];
        yield 'JSON' => ['{"a":1}', false];
        yield 'a PDF' => ["%PDF-1.7\n", false];
    }

    #[DataProvider('heads')]
    public function testTheFirstBytesDecide(string $head, bool $expected): void
    {
        self::assertSame($expected, SpreadsheetSignature::matches($head));
    }

    public function testAFileIsReadEightBytesDeepAndNoFurther(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-signature-');
        self::assertNotFalse($path);

        try {
            file_put_contents($path, "PK\x03\x04".str_repeat("\x00", 4096));
            self::assertTrue(SpreadsheetSignature::matchesFile($path));

            file_put_contents($path, "name;email\n");
            self::assertFalse(SpreadsheetSignature::matchesFile($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * ⚠️ A missing file is not a workbook. Whether it is an error is the caller's question, and the
     * reader that follows fails with a message about the file — which is the useful one.
     */
    public function testAMissingFileIsNotAWorkbook(): void
    {
        self::assertFalse(SpreadsheetSignature::matchesFile(sys_get_temp_dir().'/dataflow-absent-'.uniqid()));
    }
}
