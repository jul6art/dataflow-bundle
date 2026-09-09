<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Translation;

use Jul6Art\CoreBundle\Translation\JsTranslationScanner;
use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Translation\DeclaredTranslationKeys;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every translation key the shipped JavaScript reads, checked against the shipped catalogue.
 *
 * ## The defect this exists for
 *
 * ⚠️ The screen this controller is extracted from displayed **thirty-four raw translation keys**,
 * across its whole surface, in production. Nothing failed: the template passed no payload, the
 * controller asked for keys nobody had translated, and every test was green because each half was
 * only ever asserted against itself.
 *
 * With the catalogue shipped by the bundle there is no second half to drift from — the key the
 * controller reads IS the key this bundle translates — so the guard can be exact rather than
 * approximate: **every literal key must have an entry, and every entry must be under the bundle's
 * own namespace.**
 */
#[CoversNothing]
final class AssetTranslationKeysTest extends TestCase
{
    /**
     * ⚠️ `dataflow.` and nothing else. The extracted controller read `filters.op.eq` and eleven
     * siblings — twelve labels under no namespace at all — and a project cannot be asked to
     * translate a key that looks like it belongs to nobody.
     */
    public function testEveryKeyLivesUnderTheBundleNamespace(): void
    {
        $scan = new JsTranslationScanner()->scan(self::assetsDir());

        $foreign = array_values(array_filter(
            $scan->keys(),
            static fn (string $key): bool => !str_starts_with($key, DeclaredTranslationKeys::PREFIX),
        ));

        self::assertSame([], $foreign, \sprintf(
            "These keys sit outside the bundle's namespace, so nobody translates them:\n  - %s",
            implode("\n  - ", $foreign),
        ));
    }

    /**
     * ⚠️ The whole point. A key the JavaScript asks for and the catalogue does not carry is a raw
     * `dataflow.builder.columns.none` rendered to a user — and it is invisible in every other test,
     * because asking for a missing key is not an error anywhere.
     */
    public function testEveryKeyTheJavaScriptReadsIsInTheShippedCatalogue(): void
    {
        $catalogue = self::catalogue();

        $missing = array_values(array_filter(
            new JsTranslationScanner()->scan(self::assetsDir())->keys(),
            static fn (string $key): bool => !\in_array($key, $catalogue, true),
        ));

        self::assertSame([], $missing, \sprintf(
            "The JavaScript reads these keys and dataflow.en.xlf does not carry them:\n  - %s",
            implode("\n  - ", $missing),
        ));
    }

    /**
     * ⚠️ And the declared ones too — those are precisely the keys no scanner can see, so nothing
     * else would notice their absence.
     */
    public function testEveryDeclaredKeyIsInTheShippedCatalogue(): void
    {
        $catalogue = self::catalogue();

        foreach (new DeclaredTranslationKeys()->keys() as $key) {
            self::assertContains($key, $catalogue);
        }
    }

    /**
     * ⚠️ **No lookup is completely unreadable.** A template literal with a constant head — the
     * twelve operator labels — is filed by the scanner as a PREFIX, which can still be checked; one
     * whose key is built entirely at run time is filed as dynamic and can be checked by nothing.
     * Zero of the latter is the property worth holding: it means every key this bundle asks for is
     * either in the catalogue or under a declared prefix.
     */
    public function testNoLookupIsCompletelyUnreadable(): void
    {
        $dynamic = new JsTranslationScanner()->scan(self::assetsDir())->dynamicCalls();

        self::assertSame([], $dynamic, \sprintf(
            "These lookups build their key at run time, so nothing can check them:\n  - %s",
            implode("\n  - ", $dynamic),
        ));
    }

    /**
     * And the one prefix that IS used has its family declared — which closes the loop: the scanner
     * finds a prefix, {@see DeclaredTranslationKeys} names the keys under it, and the two tests
     * above put those keys in the catalogue.
     */
    public function testEveryPrefixFamilyIsCoveredByADeclaration(): void
    {
        $prefixes = new JsTranslationScanner()->scan(self::assetsDir())->prefixes();
        $declared = new DeclaredTranslationKeys()->keys();

        self::assertSame(['dataflow.filter.op.'], $prefixes);

        foreach ($prefixes as $prefix) {
            $covered = array_filter($declared, static fn (string $key): bool => str_starts_with($key, $prefix));

            self::assertNotSame([], $covered, \sprintf(
                'The JavaScript reads keys under "%s" and nothing declares them.',
                $prefix,
            ));
        }
    }

    /**
     * ⚠️ The one consistency no compiler checks: the operator codes live in a PHP enum and in a
     * JavaScript array, in two languages, and the interpreter drops an unknown code **in silence**
     * — the filter simply vanishes from the report. So a code the screen offers and the enum does
     * not know is a filter the user sets and the server ignores.
     */
    public function testTheOperatorCodesInTheControllerMatchTheEnum(): void
    {
        $source = file_get_contents(self::assetsDir().'/controllers/report_builder_controller.js');
        self::assertIsString($source);

        $block = preg_match('/static OPERATORS = \[(.*?)\];/s', $source, $matches);
        self::assertSame(1, $block, 'The OPERATORS list has moved or changed shape.');

        preg_match_all("/code: '([^']+)'/", $matches[1], $codes);

        $inJavaScript = $codes[1];
        sort($inJavaScript);

        $inPhp = array_map(static fn (FilterOperator $case): string => $case->value, FilterOperator::cases());
        sort($inPhp);

        self::assertSame($inPhp, $inJavaScript);
    }

    private static function assetsDir(): string
    {
        return \dirname(__DIR__, 2).'/assets';
    }

    /**
     * @return list<string> every `resname` in the shipped English catalogue
     */
    private static function catalogue(): array
    {
        $xml = simplexml_load_file(\dirname(__DIR__, 2).'/Resources/translations/dataflow.en.xlf');
        self::assertNotFalse($xml);

        $keys = [];

        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $keys[] = (string) $unit['resname'];
        }

        return $keys;
    }
}
