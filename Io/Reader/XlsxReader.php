<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Reader;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads a real spreadsheet, through OpenSpout — the mirror of {@see \Jul6Art\DataflowBundle\Io\Writer\XlsxWriter}.
 *
 * ## `supports()` answers a NARROWER question than {@see \Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature}
 *
 * ⚠️ The guard answers "is this a binary container rather than text", which is true of a `.docx`, a
 * `.ods` and an old binary `.xls` (OLE2) as much as of a real `.xlsx` — it exists to make CSV refuse
 * usefully, not to recognise a format positively. This reader can only open OOXML spreadsheets, so
 * `supports()` opens the ZIP and looks for `xl/workbook.xml`: present in every `.xlsx`, absent from
 * a `.docx` (`word/document.xml`), an `.ods` (`content.xml`) and, because it is not a ZIP at all,
 * from an old binary `.xls`. **That last case matters most**: without this narrower check, a real
 * `.xls` would satisfy the guard, this reader would claim to support it, and `ZipArchive::open()`
 * would fail on bytes that were never a ZIP — trading D-14's silent "imported: 0" for a loud crash
 * instead of the clear, named refusal the guard was built to give.
 *
 * ## Only the first sheet, and record numbers that mean what CSV's mean
 *
 * ⚠️ **One sheet.** A tabular import has one table; a second sheet in the workbook is ignored
 * rather than guessed at — silently picking "the biggest one" or "the one with data" would import
 * the wrong sheet exactly when a user left a scratch tab behind, which is the one time this would
 * matter and the one time nobody would think to check.
 *
 * ⚠️ **The key is the row's actual position in the sheet, not a count of rows read**, obtained by
 * asking OpenSpout to preserve empty rows (`SHOULD_PRESERVE_EMPTY_ROWS`). Without it, a deleted or
 * hidden row disappears from the count instead of the numbering, and record 40 of the report points
 * at row 41 of the spreadsheet the user has open. This is the exact property
 * {@see TabularReaderInterface} documents for CSV; a workbook keeping its row index in the XML makes
 * it free to honour here too, instead of merely unavoidable.
 *
 * ⚠️ **A row with zero cells consumes its number and yields nothing — a row of empty STRINGS does
 * not.** The first is what `SHOULD_PRESERVE_EMPTY_ROWS` synthesises for a gap in the sheet, the
 * exact counterpart of a blank CSV line. The second is a real row whose cells are simply blank
 * (`,,,` in CSV terms) and is data, not a gap.
 *
 * ## Every cell comes back as a string, whatever Excel stored
 *
 * ⚠️ **Dates are read as the FORMATTED text Excel would show**, via `SHOULD_FORMAT_DATES`, not as a
 * `DateTimeImmutable` — this reader's contract is `list<string>`, exactly like CSV's, and a mapper
 * written against one must not special-case the other. Formatting from the cell's OWN number format
 * is a per-file, not a per-locale, decision: {@see \Jul6Art\DataflowBundle\Report\Transformer} owns
 * locale-aware formatting on the way OUT, and nothing upstream of an import knows the operator's
 * locale to begin with.
 *
 * ⚠️ **A boolean cell becomes `'1'` or `'0'`**, never `'true'`/`'false'` or a localised "Oui"/"Non" —
 * translating a cell before the mapper sees it would bake one language into every import.
 */
final readonly class XlsxReader implements TabularReaderInterface
{
    #[\Override]
    public function code(): string
    {
        return 'xlsx';
    }

    #[\Override]
    public function supports(string $filePath): bool
    {
        $zip = new \ZipArchive();

        if (true !== @$zip->open($filePath)) {
            return false;
        }

        try {
            return false !== $zip->locateName('xl/workbook.xml');
        } finally {
            $zip->close();
        }
    }

    #[\Override]
    public function read(string $filePath): \Generator
    {
        // Re-checked here, exactly as CsvReader re-checks its own signature guard: a caller that
        // skips supports() still gets the named refusal instead of whatever ZipArchive/OpenSpout
        // happens to do with bytes that are a ZIP but not a workbook.
        if (!$this->supports($filePath)) {
            throw UnreadableFileException::cannotOpen($filePath);
        }

        $reader = new Reader(new Options(
            SHOULD_FORMAT_DATES: true,
            SHOULD_PRESERVE_EMPTY_ROWS: true,
        ));

        try {
            $reader->open($filePath);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $record => $row) {
                        if (!\is_int($record)) {
                            // Unreachable at runtime: RowIteratorInterface's key() is declared as
                            // Iterator's generic mixed, but every implementation returns the row's
                            // 1-based position as an int. Narrowed here rather than cast, so a
                            // future OpenSpout that broke this would fail loudly instead of quietly
                            // mis-numbering every record after it.
                            continue;
                        }

                        $cells = \array_values(\array_map(self::cellToString(...), $row->toArray()));

                        if ([] === $cells) {
                            // A gap in the sheet: consumes its record number, yields nothing —
                            // the exact counterpart of a blank CSV line.
                            continue;
                        }

                        yield $record => $cells;
                    }

                    // One sheet, on purpose — see the class docblock.
                    break;
                }
            } finally {
                $reader->close();
            }
        } catch (OpenSpoutException $exception) {
            throw UnreadableFileException::cannotOpen($filePath);
        }
    }

    /**
     * @param array<array-key, TextRun>|bool|\DateInterval|\DateTimeInterface|float|int|string|null $value
     */
    private static function cellToString(
        array|bool|\DateInterval|\DateTimeInterface|float|int|string|null $value,
    ): string {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \DateInterval => $value->format('%d days'),
            // Rich text runs come back as an array of fragments; joined rather than dropped.
            \is_array($value) => \implode('', \array_map(static fn (TextRun $run): string => $run->text, $value)),
            default => (string) $value,
        };
    }
}
