<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Format;

/**
 * Applies one column's {@see ColumnFormat}, if it has one.
 *
 * ## Why this runs BEFORE {@see \Jul6Art\DataflowBundle\Report\Transformer\ValueTransformerChain},
 * not instead of it
 *
 * ⚠️ **A formatted value is already a string, so the chain no-ops on it.** `ValueTransformerChain`
 * only acts on a `DateTimeInterface`, a `bool`, a `BackedEnum` — types a formatted column no longer
 * is once this class has rendered it. A column with NO format keeps reaching the chain exactly as
 * before: this class is a narrower, EARLIER pass, not a replacement for the general one.
 *
 * ⚠️ **Dates are NOT delegated to {@see NumberFormatterInterface}.** `core-bundle` has no date
 * formatter to delegate to, and inventing a full `IntlDateFormatter` wrapper for two fixed patterns
 * would be the abstraction this bundle's own conventions warn against building before a second
 * consumer asks for a second pattern. `dateFormat` / `dateTimeFormat` are constructor strings,
 * reconfigurable exactly like `core-bundle`'s own separators are — not runtime, per-locale, but
 * once, for the application.
 *
 * ⚠️ **A date column reaching this class is usually already a STRING, not a `DateTimeInterface`.**
 * Verified empirically, not assumed: {@see \Jul6Art\DataflowBundle\Report\ReportRunner} hydrates
 * with `HYDRATE_SCALAR`, and a Doctrine `date`/`datetime` column under scalar hydration comes back
 * as its ISO text on SQLite as on PostgreSQL — {@see \Jul6Art\DataflowBundle\Report\Transformer\DateTimeValueTransformer}'s
 * own `instanceof` check is therefore inert on the very pipeline it was written for, a fact this
 * class does not repeat: `Kind::Date` and `Kind::DateTime` accept EITHER shape, parsing the string
 * case with `DateTimeImmutable`'s own constructor rather than trusting one hydration path to hold.
 */
final readonly class ColumnFormatter
{
    public function __construct(
        private NumberFormatterInterface $numbers = new PassthroughNumberFormatter(),
        private string $dateFormat = 'd/m/Y',
        private string $dateTimeFormat = 'd/m/Y H:i',
    ) {
    }

    public function format(mixed $value, ?ColumnFormat $format): mixed
    {
        if (null === $format || null === $value) {
            return $value;
        }

        return match ($format->kind) {
            Kind::Number => $this->numbers->format($value, $format->decimals),
            Kind::Money => $this->numbers->formatMoney($value, $format->currency ?? '', $format->decimals),
            Kind::Percent => $this->numbers->formatPercent($value, $format->decimals ?? 0),
            Kind::Date => $this->formatDateLike($value, $this->dateFormat),
            Kind::DateTime => $this->formatDateLike($value, $this->dateTimeFormat),
        };
    }

    /**
     * @return mixed the formatted string, or $value UNCHANGED when it is neither a
     *               `DateTimeInterface` nor a string PHP can parse as one
     */
    private function formatDateLike(mixed $value, string $pattern): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($pattern);
        }

        if (!\is_string($value)) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value)->format($pattern);
        } catch (\Exception) {
            // Not a date PHP can parse — a column formatted as a date by mistake, most likely.
            // Left alone rather than blanked: an unreadable value is more useful than a silent gap.
            return $value;
        }
    }
}
