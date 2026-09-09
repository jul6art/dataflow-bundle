<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Dialect;

/**
 * The shape of a CSV file: separator, quoting, escaping, byte-order mark, line ending.
 *
 * ## Why this is a value object and not five arguments
 *
 * Before the extraction, the two applications of this ecosystem wrote CSV in **eleven** places and
 * produced **four** different dialects — `;` with a backslash escape, `,` with none, `,` with a
 * backslash and no byte-order mark, and tab-separated. A customer exporting two screens of the same
 * product received two dialects, and under Excel FR configured on `;` one of the two files opened
 * as a single column. Naming the shape once is what stops that.
 *
 * ## The two settings that are never cosmetic
 *
 * ⚠️ **`escape` defaults to the empty string, and that is a correctness fix.** PHP's own default is
 * the backslash, which **is not CSV**: the standard escapes a quote by doubling it. With the
 * default, a value ending in a backslash breaks the quoting of the *next* field and shifts a whole
 * column in the reader's spreadsheet. PHP 8.4 made the parameter mandatory for this reason; `''`
 * disables the proprietary escape and lets standard quoting do its work.
 *
 * ⚠️ **`bom` decides whether Excel reads the file as UTF-8 at all.** Without it Excel FR falls back
 * to Latin-1 and « prénom » renders « prÃ©nom ». One export of this ecosystem shipped without it
 * while six others had it, and nothing but a human eye caught the difference.
 *
 * ## Picking one
 *
 * ```php
 * CsvDialect::excelFr();      // ; " '' BOM CRLF — a file destined to be double-clicked in France
 * CsvDialect::rfc4180();      // , " ''     CRLF — a file destined to be parsed
 * CsvDialect::tabSeparated(); // TAB " ''     LF — an imposed format, e.g. the French FEC
 * ```
 *
 * Anything else is a deliberate exception and reads as one at the call site.
 */
final readonly class CsvDialect
{
    /**
     * @param non-empty-string $delimiter
     * @param non-empty-string $enclosure
     * @param non-empty-string $lineEnding
     */
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public string $escape = '',
        public bool $bom = false,
        public string $lineEnding = "\r\n",
    ) {
    }

    /**
     * A file meant to be double-clicked in a French locale: semicolon, byte-order mark, CRLF.
     */
    public static function excelFr(): self
    {
        return new self(delimiter: ';', bom: true);
    }

    /**
     * RFC 4180 proper: comma, CRLF, no byte-order mark. The right choice for a file another program
     * parses — a mark it does not expect becomes part of the first column name.
     */
    public static function rfc4180(): self
    {
        return new self();
    }

    /**
     * Tab-separated, LF, no byte-order mark. For formats that impose it, such as the French FEC.
     */
    public static function tabSeparated(): self
    {
        return new self(delimiter: "\t", lineEnding: "\n");
    }
}
