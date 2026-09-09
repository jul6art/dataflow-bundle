<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Reader;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Io\Dialect\CsvDialect;
use Jul6Art\DataflowBundle\Io\Guard\SpreadsheetSignature;

/**
 * Reads CSV, one record at a time, in the dialect it is given.
 *
 * ## Three things this class does that a naive `fgetcsv` loop does not
 *
 * ⚠️ **It strips a leading byte-order mark.** A file written by Excel FR starts with `EF BB BF`,
 * so the first header cell arrives as `\xEF\xBB\xBFemail` and matches no field. The column is then
 * "unrecognised" and the user is told to map a column whose name looks identical to the one they
 * expected — three invisible bytes producing a support ticket nobody can reproduce by eye. This is
 * the exact counterpart of the mark the writers add, and the reason the two live side by side.
 *
 * ⚠️ **It honours the dialect's `escape`, which defaults to the empty string.** PHP's own default
 * is the backslash, which is not CSV: with it, a value ending in a backslash swallows the following
 * quote and shifts every remaining column of that record. The writers were fixed first; a reader
 * left on PHP's default would have re-created the defect on the way back in.
 *
 * ⚠️ **It refuses a binary workbook instead of reading its bytes as text.** Without this, a real
 * `.xls` yields a handful of records of mojibake, nothing maps, and the screen says "imported: 0,
 * skipped: 4 812" without naming the cause.
 *
 * ## One limitation, stated rather than hidden
 *
 * A file whose lines end in a bare `\r` — a classic Mac OS 9 export — is read as a single record.
 * PHP's `auto_detect_line_endings` used to paper over it and was deprecated in 8.1 for being a
 * global mutable setting. Such a file is rare enough, and mangled enough, that guessing would do
 * more harm than the clear failure of one enormous record.
 */
final readonly class CsvReader implements TabularReaderInterface
{
    private const string BOM = "\xEF\xBB\xBF";

    public function __construct(
        private CsvDialect $dialect = new CsvDialect(),
    ) {
    }

    #[\Override]
    public function code(): string
    {
        return 'csv';
    }

    /**
     * ⚠️ The answer is a refusal, not a recognition: anything that is not a binary container can be
     * read as CSV, because that is what CSV is. A reader claiming to "detect" CSV positively would
     * have to guess, and guessing wrong on a delimiter silently produces a one-column file.
     */
    #[\Override]
    public function supports(string $filePath): bool
    {
        return !SpreadsheetSignature::matchesFile($filePath);
    }

    #[\Override]
    public function read(string $filePath): \Generator
    {
        if (SpreadsheetSignature::matchesFile($filePath)) {
            throw UnreadableFileException::binaryWorkbook();
        }

        $handle = @\fopen($filePath, 'rb');

        if (false === $handle) {
            throw UnreadableFileException::cannotOpen($filePath);
        }

        try {
            $record = 0;
            $first = true;

            while (false !== ($cells = \fgetcsv(
                $handle,
                null,
                $this->dialect->delimiter,
                $this->dialect->enclosure,
                $this->dialect->escape,
            ))) {
                ++$record;

                // A blank line: `fgetcsv` reports it as a single null cell. It still consumes its
                // record number, so every number after it keeps pointing at the right row.
                if ([null] === $cells) {
                    continue;
                }

                $row = [];

                foreach ($cells as $cell) {
                    $row[] = $cell ?? '';
                }

                if ($first) {
                    // Stripped from the first cell YIELDED rather than from record 1: a file that
                    // opens with a blank line puts the mark on a record that is never yielded.
                    $row[0] = self::withoutBom($row[0]);
                    $first = false;
                }

                yield $record => $row;
            }
        } finally {
            // Runs when the caller stops early too: destroying a suspended generator unwinds it.
            \fclose($handle);
        }
    }

    private static function withoutBom(string $cell): string
    {
        return \str_starts_with($cell, self::BOM) ? \substr($cell, \strlen(self::BOM)) : $cell;
    }
}
