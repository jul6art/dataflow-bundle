<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Writer;

use Jul6Art\DataflowBundle\Io\Guard\FormulaInjectionGuard;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes a real spreadsheet, through OpenSpout.
 *
 * ## Why OpenSpout and not PhpSpreadsheet
 *
 * OpenSpout is streaming-first, needs nothing but zip and xml, and uses roughly a seventh of the
 * memory on a large export.
 *
 * ⚠️ **The reason usually given for this choice is stale, and saying so here is the point.** The
 * original note read « PhpSpreadsheet requires `ext-gd`, which is not in our Docker image ». That
 * became false the day the image gained `gd` for a PDF renderer, and someone will eventually
 * conclude « gd is there now, so we can switch ». The decision stands on the streaming argument
 * alone.
 *
 * ## A workbook cannot be streamed to the client, but it can be streamed to disk
 *
 * ⚠️ OpenSpout needs a real path: a `.xlsx` is a ZIP archive whose central directory is written
 * last, so no prefix of the file is a valid document. Rows are therefore written to a temporary
 * file as they arrive — the row source is still consumed lazily, which is what keeps memory flat.
 *
 * ⚠️ **The finished file is then emitted in CHUNKS, never as one string.** The version this
 * replaces ended with `$emit((string) file_get_contents($tmp))`, which loads the whole workbook
 * into a single PHP string to hand it over — undoing, in one line, the memory discipline of
 * everything above it. A 40 MB export needed 40 MB of string on top of the file that already
 * existed.
 */
final readonly class XlsxWriter implements TabularWriterInterface
{
    /**
     * 64 KiB. Large enough that the syscall count is irrelevant, small enough that the peak stays
     * flat whatever the workbook weighs.
     */
    private const int CHUNK_BYTES = 65536;

    #[\Override]
    public function code(): string
    {
        return 'xlsx';
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    #[\Override]
    public function fileExtension(): string
    {
        return 'xlsx';
    }

    #[\Override]
    public function write(array $header, iterable $rows, callable $emit): void
    {
        // ⚠️ `tempnam()` CREATES the file, and its path is used AS IS. Appending `.xlsx` to it —
        // which the first version did — writes into a second file and orphans the first: one
        // leaked inode per export, invisible until `/tmp` fills up. OpenSpout does not read the
        // extension; the writer class decides the format.
        $path = \tempnam(\sys_get_temp_dir(), 'dataflow_');

        if (false === $path) {
            return;
        }

        try {
            $this->fill($path, $header, $rows);
            $this->emitInChunks($path, $emit);
        } finally {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }
    }

    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     */
    private function fill(string $path, array $header, iterable $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($path);

        try {
            if ([] !== $header) {
                // OpenSpout v5 styles are immutable, built through `withX()` cloners.
                $style = new Style()
                    ->withFontBold(true)
                    ->withBackgroundColor(Color::rgb(239, 239, 239));

                // ⚠️ The header goes through the guard too: in a report builder its labels are
                // typed by the user, so it is an injection vector exactly like the data.
                $writer->addRow(Row::fromValuesWithStyle(
                    \array_map(
                        static fn (string|int|float|bool|null $cell): string => (string) FormulaInjectionGuard::neutralize($cell),
                        $header,
                    ),
                    $style,
                ));
            }

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(FormulaInjectionGuard::neutralizeRow($row)));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param callable(string $chunk): void $emit
     */
    private function emitInChunks(string $path, callable $emit): void
    {
        $handle = \fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            while (!\feof($handle)) {
                $chunk = \fread($handle, self::CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $emit($chunk);
            }
        } finally {
            \fclose($handle);
        }
    }
}
