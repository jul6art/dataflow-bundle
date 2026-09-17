<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Switching entity in the builder clears the columns and the filters.
 *
 * ## The bug this closes
 *
 * ⚠️ `_columns` and `_filters` describe ONE entity. While `entityChanged()` only reloaded the field
 * list, a filter composed on the first entity survived the switch and was posted as-is: the server
 * refused a `startDate` on an entity without that field, and the screen said « the preview could
 * not be built » — showing nothing of the ghost filter behind it, since the filters step was not
 * even the one being looked at.
 *
 * ⚠️ The opposite guard matters as much: re-clicking the entity ALREADY chosen must throw nothing
 * away. Without it, the fix turned a harmless click into lost work.
 *
 * ## Why a TEXT test
 *
 * ⚠️ This suite has no browser and no JavaScript engine: reading the file is the whole mechanism,
 * which is also why it stays this small. It does not prove the screen behaves — it stops the reset
 * from quietly disappearing in a refactor, which the original bug proved possible.
 */
#[CoversNothing]
final class EntitySwitchResetsDefinitionTest extends TestCase
{
    public function testEntityChangedClearsColumnsAndFilters(): void
    {
        $body = self::entityChangedBody();

        self::assertStringContainsString('this._columns = [];', $body, 'Columns of the previous entity would survive the switch.');
        self::assertStringContainsString('this._filters = [];', $body, 'Filters of the previous entity would reach the server, which refuses them.');
        self::assertStringContainsString('this._renderFilters();', $body, 'Filter rows would stay on screen after the reset.');
    }

    public function testEntityChangedLeavesTheAlreadyChosenEntityAlone(): void
    {
        $body = self::entityChangedBody();

        self::assertMatchesRegularExpression(
            '/if \(chosen === this\._entity\) \{\s*return;/',
            $body,
            'Re-clicking the current entity would throw away a half-composed report.',
        );
    }

    /**
     * The body of `entityChanged()`, from its name to the next method.
     */
    private static function entityChangedBody(): string
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/report_builder_controller.js');

        self::assertIsString($source);

        $start = strpos($source, 'async entityChanged(');

        self::assertIsInt($start, 'entityChanged() not found: the guard would pass on nothing.');

        // The next method opens at the same indentation; failing that, read to the end of the file.
        $end = strpos($source, "\n    async ", $start + 1);
        $alternative = strpos($source, "\n    _", $start + 1);

        if (false !== $alternative and (false === $end or $alternative < $end)) {
            $end = $alternative;
        }

        return substr($source, $start, false === $end ? null : $end - $start);
    }
}
