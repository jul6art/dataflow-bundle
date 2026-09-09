<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Writer;

use Jul6Art\DataflowBundle\Io\Writer\XlsxWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The spreadsheet writer.
 *
 * ⚠️ A `.xlsx` is a ZIP archive, so the assertions read it back rather than matching bytes.
 *
 * ⚠️ And they read `xl/worksheets/sheet1.xml`, not `xl/sharedStrings.xml`. A first version of this
 * file assumed the shared-strings table — OpenSpout v5 writes values **inline** (`<is><t>…`), so
 * that table came back with `count="0"` and every assertion failed against an empty document. The
 * premise was wrong, not the writer.
 */
#[CoversClass(XlsxWriter::class)]
final class XlsxWriterTest extends TestCase
{
    public function testTheOutputIsAReadableWorkbookCarryingItsCells(): void
    {
        $strings = $this->sheetXml($this->write(['name', 'total'], [['Dupont', '100.00']]));

        self::assertStringContainsString('name', $strings);
        self::assertStringContainsString('Dupont', $strings);
    }

    public function testAFormulaIsNeutralisedInTheBodyAndInTheHeader(): void
    {
        $strings = $this->sheetXml($this->write(['=1+1'], [['=HYPERLINK("x")']]));

        self::assertStringContainsString("'=1+1", $strings);
        self::assertStringContainsString("'=HYPERLINK", $strings);
    }

    /**
     * ⚠️ The counter-proof, and the one that would break every accounting workbook if it failed.
     */
    public function testANegativeAmountIsNotNeutralised(): void
    {
        $out = $this->write([], [['-100.00']]);

        self::assertStringNotContainsString("'-100.00", $this->sheetXml($out));
    }

    /**
     * ⚠️ The correctness of D-3: the finished workbook must reach the caller in several chunks, not
     * as one string. Asserting the content cannot see the difference — only the chunk count can.
     *
     * The row count is chosen so the archive comfortably exceeds one 64 KiB chunk.
     */
    public function testAWorkbookIsEmittedInSeveralChunks(): void
    {
        $rows = (static function (): \Generator {
            for ($i = 0; $i < 20000; ++$i) {
                yield ['row-'.$i, 'a fairly long value so the archive grows past one chunk', $i];
            }
        })();

        $chunks = [];

        new XlsxWriter()->write(['a', 'b', 'c'], $rows, static function (string $chunk) use (&$chunks): void {
            $chunks[] = \strlen($chunk);
        });

        self::assertGreaterThan(1, \count($chunks), 'The workbook came back in more than one chunk.');

        // Every chunk, not just the largest: `max()` on a possibly-empty list is what PHPStan
        // rejected, and naming each one says more about what the writer promised.
        foreach ($chunks as $size) {
            self::assertLessThanOrEqual(65536, $size, 'No chunk exceeds the declared size.');
        }
    }

    /**
     * ⚠️ Nothing is left in the temporary directory. The writer buffers to disk by necessity; a
     * failure to clean up would fill `/tmp` one export at a time, which is the kind of defect that
     * only shows up in production.
     */
    public function testTheTemporaryFileIsRemoved(): void
    {
        $before = glob(sys_get_temp_dir().'/dataflow_*') ?: [];

        $this->write(['a'], [['b']]);

        $after = glob(sys_get_temp_dir().'/dataflow_*') ?: [];

        self::assertSame($before, $after);
    }

    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     */
    private function write(array $header, iterable $rows): string
    {
        $out = '';

        new XlsxWriter()->write($header, $rows, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    /**
     * Reads `xl/worksheets/sheet1.xml` out of the archive — where OpenSpout puts cell values — and
     * DECODES its entities.
     *
     * ⚠️ The decoding is what makes the assertions version-independent, and the CI's `lowest deps`
     * job is what said so. Recent OpenSpout writes the text marker as a literal `'`; the oldest
     * version this bundle accepts writes it as `&#039;`. The guard behaves identically in both — it
     * is the XML escaping of a dependency that differs — so an assertion on the raw markup was
     * coupled to a version rather than to the property under test. Four green `composer qa` runs
     * did not see it.
     */
    private function sheetXml(string $archive): string
    {
        $path = tempnam(sys_get_temp_dir(), 'probe_');
        file_put_contents($path, $archive);

        try {
            $zip = new \ZipArchive();
            self::assertTrue(true === $zip->open($path), 'The output is a readable ZIP archive.');

            $strings = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertIsString($strings, 'The archive carries a worksheet.');

            return html_entity_decode($strings, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        } finally {
            @unlink($path);
        }
    }
}
