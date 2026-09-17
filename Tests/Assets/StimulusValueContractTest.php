<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every value the shipped partial publishes is one the shipped controller declares.
 *
 * ## Why this pair breaks silently
 *
 * ⚠️ A Stimulus value is a contract spelled out TWICE, in two languages, and neither end complains
 * when they disagree. `data-…-select2-identifier-value` in the Twig file has to meet
 * `select2Identifier` in the JavaScript, kebab-case against camelCase; miss the conversion and the
 * controller reads its default forever. No error, no warning, no 404 — the feature is simply
 * absent, which is indistinguishable from "not implemented yet" to everyone who did not write it.
 *
 * ⚠️ This ecosystem has already paid for the same class of mistake at the identifier level: a relay
 * named `report_builder_controller.js` registered `dataflow--report_builder` while the partial
 * emitted `dataflow--report-builder`, and the screen was loaded, registered and connected to
 * NOTHING. Found in a browser, never by a test. This guard is the cheap version of that lesson,
 * one level down.
 *
 * ## What it checks, and what it cannot
 *
 * ⚠️ One direction only: everything PUBLISHED must be DECLARED. The reverse is legitimate — a value
 * a consumer sets on its own markup, or one that only ever uses its default, is declared without
 * appearing here.
 *
 * ⚠️ It reads the files as text. There is no Stimulus in this suite and no browser, so the parse is
 * the whole mechanism — which is also why it stays this small.
 */
#[CoversNothing]
final class StimulusValueContractTest extends TestCase
{
    public function testEveryValuePublishedByThePartialIsDeclaredByTheController(): void
    {
        $published = self::publishedValues();
        $declared = self::declaredValues();

        self::assertNotSame([], $published, 'No value found in the partial: the guard would pass on nothing.');
        self::assertNotSame([], $declared, 'No value found in the controller: the guard would pass on nothing.');

        $orphans = array_values(array_diff($published, $declared));
        sort($orphans);

        self::assertSame([], $orphans, \sprintf(
            "The partial publishes these values and the controller declares none of them, so each reads its default forever:\n  - %s\n\nDeclared: %s",
            implode("\n  - ", $orphans),
            implode(', ', $declared),
        ));
    }

    /**
     * `data-{{ stimulus }}-some-name-value="…"` in the shipped partial.
     *
     * @return list<string>
     */
    private static function publishedValues(): array
    {
        $twig = file_get_contents(\dirname(__DIR__, 2).'/Resources/views/report/_builder.html.twig');
        self::assertIsString($twig);

        preg_match_all('/data-\{\{ stimulus \}\}-([a-z0-9-]+)-value/', $twig, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The keys of `static values = { … }` in the shipped controller, kebab-cased.
     *
     * @return list<string>
     */
    private static function declaredValues(): array
    {
        $js = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/report_builder_controller.js');
        self::assertIsString($js);

        $start = strpos($js, 'static values = {');
        self::assertIsInt($start, 'The controller no longer declares `static values`.');

        $end = strpos($js, '};', $start);
        self::assertIsInt($end);

        preg_match_all('/^\s{8}([A-Za-z][A-Za-z0-9]*):/m', substr($js, $start, $end - $start), $matches);

        return array_map(
            static fn (string $name): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name)),
            $matches[1],
        );
    }
}
