<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Report\Format;

use Jul6Art\DataflowBundle\Report\Format\PassthroughNumberFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PassthroughNumberFormatter::class)]
final class PassthroughNumberFormatterTest extends TestCase
{
    public function testANumberIsFormattedWithTwoDecimalsByDefault(): void
    {
        self::assertSame('1234.50', new PassthroughNumberFormatter()->format(1234.5));
    }

    public function testDecimalsCanBeOverridden(): void
    {
        self::assertSame('1234.500', new PassthroughNumberFormatter()->format(1234.5, 3));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function nothingToFormat(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'not numeric' => ['abc'];
    }

    #[DataProvider('nothingToFormat')]
    public function testNothingToFormatIsAnEmptyString(?string $value): void
    {
        self::assertSame('', new PassthroughNumberFormatter()->format($value));
    }

    public function testMoneyAppendsTheCurrencyCode(): void
    {
        self::assertSame('100.00 EUR', new PassthroughNumberFormatter()->formatMoney(100, 'EUR'));
    }

    public function testMoneyOnNothingToFormatStaysEmpty(): void
    {
        self::assertSame('', new PassthroughNumberFormatter()->formatMoney(null, 'EUR'));
    }

    public function testPercentDefaultsToNoDecimal(): void
    {
        self::assertSame('42%', new PassthroughNumberFormatter()->formatPercent(42));
    }

    public function testPercentOnNothingToFormatStaysEmpty(): void
    {
        self::assertSame('', new PassthroughNumberFormatter()->formatPercent(null));
    }
}
