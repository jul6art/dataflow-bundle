<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Reader;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Io\Reader\XlsxReader;
use Jul6Art\DataflowBundle\Io\Writer\XlsxWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The spreadsheet reader — the mirror of {@see \Jul6Art\DataflowBundle\Tests\Io\Writer\XlsxWriterTest}.
 *
 * ⚠️ Most fixtures round-trip through {@see XlsxWriter}: it is the one guaranteed-valid producer of
 * `.xlsx` bytes this test suite has. The gap-numbering test cannot, because the writer never leaves
 * a gap — it is built from a hand-written minimal workbook instead, which is also the only way to
 * pin what OpenSpout's `SHOULD_PRESERVE_EMPTY_ROWS` actually does.
 */
#[CoversClass(XlsxReader::class)]
final class XlsxReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testTheFormatCodeIsXlsx(): void
    {
        self::assertSame('xlsx', new XlsxReader()->code());
    }

    public function testRecordsAreNumberedFromOneWithTheHeaderIncluded(): void
    {
        $records = $this->read(['email', 'name'], [['a@example.test', 'Ada'], ['b@example.test', 'Bob']]);

        self::assertSame([
            1 => ['email', 'name'],
            2 => ['a@example.test', 'Ada'],
            3 => ['b@example.test', 'Bob'],
        ], $records);
    }

    /**
     * ⚠️ The property {@see \Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface} documents for
     * CSV — a gap consumes its number and yields nothing — is not automatic for a workbook: without
     * `SHOULD_PRESERVE_EMPTY_ROWS`, OpenSpout would COUNT rows read instead of reporting their actual
     * position, and record 2 here would silently mean "row 3 of the sheet".
     */
    public function testAGapInTheSheetConsumesItsRecordNumberAndYieldsNothing(): void
    {
        $path = $this->minimalWorkbook([
            '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>',
            // row 2 is absent on purpose
            '<row r="3"><c r="A3" t="inlineStr"><is><t>c</t></is></c></row>',
        ]);

        $records = iterator_to_array(new XlsxReader()->read($path));

        self::assertSame([1, 3], array_keys($records));
        self::assertSame(['a'], $records[1]);
        self::assertSame(['c'], $records[3]);
    }

    /**
     * ⚠️ The counter-proof: a row whose cells are all blank strings is DATA (`,,,` in CSV terms),
     * not a gap, and must keep its place in the sequence rather than being swallowed like one.
     *
     * ⚠️ Built by hand rather than through {@see XlsxWriter}: that writer treats an all-blank row as
     * genuinely empty and omits its cells entirely, which would silently turn this into the very gap
     * this test means to rule out.
     */
    public function testARowOfBlankCellsIsDataNotAGap(): void
    {
        $path = $this->minimalWorkbook([
            '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="B1" t="inlineStr"><is><t>b</t></is></c></row>',
            '<row r="2"><c r="A2" t="inlineStr"><is><t></t></is></c><c r="B2" t="inlineStr"><is><t></t></is></c></row>',
        ]);

        $records = iterator_to_array(new XlsxReader()->read($path));

        self::assertSame([1, 2], array_keys($records));
        self::assertSame(['', ''], $records[2]);
    }

    public function testABooleanCellBecomesOneOrZero(): void
    {
        $records = $this->read(['flag'], [[true], [false]]);

        self::assertSame(['1'], $records[2]);
        self::assertSame(['0'], $records[3]);
    }

    /**
     * ⚠️ `XlsxWriter` only ever writes a `scalar|null` row — a workbook with a genuine date CELL
     * TYPE is something an Excel user hands this reader, not something this bundle produces, hence
     * the hand-built fixture. With `SHOULD_FORMAT_DATES`, OpenSpout returns the ISO text unchanged
     * rather than parsing it into a `DateTimeImmutable`; asserting the exact string, not a fragment
     * of it, is what proves the object never gets constructed at all.
     */
    public function testADateCellComesBackAsAStringNeverAsADateTimeObject(): void
    {
        $path = $this->minimalWorkbook([
            '<row r="1"><c r="A1" t="d"><v>2024-01-15T00:00:00</v></c></row>',
        ]);

        $records = iterator_to_array(new XlsxReader()->read($path));

        self::assertSame(['2024-01-15T00:00:00'], $records[1]);
    }

    public function testSupportsAcceptsARealWorkbook(): void
    {
        $path = $this->write(['a'], [['b']]);

        self::assertTrue(new XlsxReader()->supports($path));
    }

    /**
     * ⚠️ The narrower half of the contract, and the reason this class does not simply delegate to
     * {@see \Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature}: that guard would answer true for
     * this ZIP too, and `ZipArchive::open()` would succeed on it — it is a real archive, just not a
     * spreadsheet. Without the narrower check, this reader would claim to support a `.docx` and then
     * fail deep inside OpenSpout instead of at `supports()`.
     */
    public function testSupportsRefusesAZipThatIsNotAWorkbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-notxlsx-');
        self::assertNotFalse($path);
        $this->files[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();

        self::assertFalse(new XlsxReader()->supports($path));
    }

    /**
     * ⚠️ An old binary `.xls` (OLE2) matches {@see \Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature}
     * — it IS a spreadsheet — but it is not a ZIP at all, so `ZipArchive::open()` fails outright. D-14
     * stays closed: neither reader claims it, and the caller gets a named refusal instead of a crash.
     */
    public function testSupportsRefusesAnOldBinaryWorkbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-ole2-');
        self::assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 40));

        self::assertFalse(new XlsxReader()->supports($path));
    }

    public function testSupportsRefusesPlainText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-text-');
        self::assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, "a,b\n1,2\n");

        self::assertFalse(new XlsxReader()->supports($path));
    }

    public function testReadingAnUnsupportedFileIsRefusedByName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-text-');
        self::assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, "a,b\n1,2\n");

        $this->expectException(UnreadableFileException::class);
        $this->expectExceptionMessage('dataflow.import.error.file_unreadable');

        iterator_to_array(new XlsxReader()->read($path));
    }

    public function testAMissingFileIsRefusedByName(): void
    {
        $this->expectException(UnreadableFileException::class);
        $this->expectExceptionMessage('dataflow.import.error.file_unreadable');

        iterator_to_array(new XlsxReader()->read(sys_get_temp_dir().'/dataflow-absent-'.uniqid()));
    }

    /**
     * ⚠️ Only the first sheet, on purpose — see the class docblock. A second sheet with data the
     * caller never asked for is not a table this reader guesses at.
     */
    public function testOnlyTheFirstSheetIsRead(): void
    {
        $path = $this->minimalWorkbook(
            ['<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>'],
            secondSheetRowsXml: ['<row r="1"><c r="A1" t="inlineStr"><is><t>z</t></is></c></row>'],
        );

        $records = iterator_to_array(new XlsxReader()->read($path));

        self::assertSame(['a'], $records[1]);
    }

    /**
     * @param list<string>            $header
     * @param list<list<scalar|null>> $rows
     *
     * @return array<int, list<string>>
     */
    private function read(array $header, array $rows): array
    {
        return iterator_to_array(new XlsxReader()->read($this->write($header, $rows)));
    }

    /**
     * @param list<string>            $header
     * @param list<list<scalar|null>> $rows
     */
    private function write(array $header, array $rows): string
    {
        $out = '';

        new XlsxWriter()->write($header, $rows, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        $path = tempnam(sys_get_temp_dir(), 'dataflow-xlsx-');
        self::assertNotFalse($path);
        file_put_contents($path, $out);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Assembles the smallest OOXML package OpenSpout accepts, from raw `<row>` XML — the only way to
     * put a GAP in a sheet, since {@see XlsxWriter} never leaves one.
     *
     * @param list<string> $rowsXml
     * @param list<string> $secondSheetRowsXml
     */
    private function minimalWorkbook(array $rowsXml, array $secondSheetRowsXml = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataflow-raw-xlsx-');
        self::assertNotFalse($path);
        $this->files[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Default Extension="xml" ContentType="application/xml"/>
                <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
            </Types>
            XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>
            XML);

        $secondSheet = [] !== $secondSheetRowsXml ? '<sheet name="Sheet2" sheetId="2" r:id="rId2"/>' : '';

        $zip->addFromString('xl/workbook.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                <sheets>
                    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
                    {$secondSheet}
                </sheets>
            </workbook>
            XML);

        $secondRels = [] !== $secondSheetRowsXml
            ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            : '';

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
                {$secondRels}
            </Relationships>
            XML);

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rowsXml));

        if ([] !== $secondSheetRowsXml) {
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml($secondSheetRowsXml));
        }

        $zip->close();

        return $path;
    }

    /**
     * @param list<string> $rowsXml
     */
    private function sheetXml(array $rowsXml): string
    {
        $rows = implode('', $rowsXml);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                <sheetData>{$rows}</sheetData>
            </worksheet>
            XML;
    }
}
