<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Format;

use Jul6Art\DataflowBundle\Report\Format\ColumnFormat;
use Jul6Art\DataflowBundle\Report\Format\ColumnFormatter;
use Jul6Art\DataflowBundle\Report\Format\NumberFormatterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnFormatter::class)]
final class ColumnFormatterTest extends TestCase
{
    public function testNoFormatLeavesTheValueUntouched(): void
    {
        $value = new \DateTimeImmutable('2026-01-15');

        self::assertSame($value, new ColumnFormatter()->format($value, null));
    }

    public function testANullValueStaysNullWhateverTheFormat(): void
    {
        self::assertNull(new ColumnFormatter()->format(null, ColumnFormat::number()));
    }

    public function testNumberDelegatesToTheBoundFormatter(): void
    {
        $numbers = $this->numbers();
        $formatter = new ColumnFormatter($numbers);

        self::assertSame('1234.5', $formatter->format(1234.5, ColumnFormat::number(1)));
    }

    public function testMoneyDelegatesWithItsCurrency(): void
    {
        $formatter = new ColumnFormatter($this->numbers());

        self::assertSame('100.00 CHF', $formatter->format(100, ColumnFormat::money('CHF')));
    }

    public function testPercentDelegatesWithItsDecimals(): void
    {
        $formatter = new ColumnFormatter($this->numbers());

        self::assertSame('42.0%', $formatter->format(42, ColumnFormat::percent(1)));
    }

    public function testADateIsFormattedWithTheConfiguredPattern(): void
    {
        $formatter = new ColumnFormatter(dateFormat: 'Y/m/d');

        self::assertSame('2026/01/15', $formatter->format(new \DateTimeImmutable('2026-01-15 14:30'), ColumnFormat::date()));
    }

    /**
     * ⚠️ The case that actually matters in production: {@see \Jul6Art\DataflowBundle\Report\ReportRunner}
     * hydrates with `HYDRATE_SCALAR`, and a Doctrine date column comes back as its ISO STRING, not a
     * `DateTimeImmutable` — verified empirically, not assumed. A formatter that only accepted the
     * object would silently do nothing on the one pipeline it exists for.
     */
    public function testAnIsoStringDateIsParsedAndReformatted(): void
    {
        $formatter = new ColumnFormatter(dateFormat: 'd/m/Y');

        self::assertSame('15/01/2026', $formatter->format('2026-01-15', ColumnFormat::date()));
    }

    public function testADateTimeIsFormattedWithItsOwnConfiguredPattern(): void
    {
        $formatter = new ColumnFormatter(dateTimeFormat: 'Y/m/d H:i');

        self::assertSame('2026/01/15 14:30', $formatter->format(new \DateTimeImmutable('2026-01-15 14:30'), ColumnFormat::dateTime()));
    }

    /**
     * ⚠️ A column asking for a date but holding something else — a string already, a null the
     * `null` guard above did not catch because this is testing the KIND branch specifically — is
     * left alone rather than made to look like a date it is not.
     */
    public function testADateFormatOnANonDateValueIsLeftAlone(): void
    {
        self::assertSame('not-a-date', new ColumnFormatter()->format('not-a-date', ColumnFormat::date()));
    }

    private function numbers(): NumberFormatterInterface
    {
        return new class implements NumberFormatterInterface {
            #[\Override]
            public function format(int|float|string|null $value, ?int $decimals = null): string
            {
                return number_format((float) $value, $decimals ?? 2, '.', '');
            }

            #[\Override]
            public function formatMoney(int|float|string|null $value, string $currency, ?int $decimals = null): string
            {
                return $this->format($value, $decimals ?? 2).' '.$currency;
            }

            #[\Override]
            public function formatPercent(int|float|string|null $value, int $decimals = 0): string
            {
                return $this->format($value, $decimals).'%';
            }
        };
    }
}
