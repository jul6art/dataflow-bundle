<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Format;

/**
 * The default {@see NumberFormatterInterface}, bound when a consumer has nothing to say about
 * number formatting — the same role {@see \Jul6Art\DataflowBundle\Port\NullExportAuditor} plays for
 * auditing.
 *
 * ⚠️ **Plain, not localised.** A period for the decimal, no thousands separator: the closest thing
 * to a neutral convention, and correct for the operator who never configured anything. A consumer
 * with an opinion — French or otherwise — binds its own `NumberFormatterInterface` and every column
 * that opted into `ColumnFormat::number()`/`money()`/`percent()` picks it up without touching a
 * single report.
 */
final readonly class PassthroughNumberFormatter implements NumberFormatterInterface
{
    private const int DEFAULT_DECIMALS = 2;

    #[\Override]
    public function format(int|float|string|null $value, ?int $decimals = null): string
    {
        if (null === $value || '' === $value || !\is_numeric($value)) {
            return '';
        }

        return \number_format((float) $value, \max(0, $decimals ?? self::DEFAULT_DECIMALS), '.', '');
    }

    #[\Override]
    public function formatMoney(int|float|string|null $value, string $currency, ?int $decimals = null): string
    {
        $formatted = $this->format($value, $decimals);

        return '' === $formatted ? '' : $formatted.' '.$currency;
    }

    #[\Override]
    public function formatPercent(int|float|string|null $value, int $decimals = 0): string
    {
        $formatted = $this->format($value, $decimals);

        return '' === $formatted ? '' : $formatted.'%';
    }
}
