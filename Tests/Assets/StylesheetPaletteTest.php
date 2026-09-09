<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The shipped stylesheet must only use colours Tailwind defines out of the box.
 *
 * ## The defect this exists for
 *
 * ⚠️ v1.1.0 applied `bg-primary-50`, `text-accent-600` and eight siblings — palette names that
 * exist in the theme of the application this was extracted from and nowhere else. Tailwind does not
 * warn about it; it **fails the consumer's build** with *"The `bg-primary-50` class does not
 * exist"*, pointing at a file inside `vendor/` that the consumer did not write. Nothing in this
 * bundle's own suite could see it: there is no Tailwind here.
 *
 * So the guard is a text one — crude, and it catches exactly the class of mistake that shipped.
 */
#[CoversNothing]
final class StylesheetPaletteTest extends TestCase
{
    /**
     * Tailwind's own colour names. A utility naming anything else needs the consumer's theme.
     *
     * @var list<string>
     */
    private const array TAILWIND_PALETTES = [
        'inherit', 'current', 'transparent', 'black', 'white',
        'slate', 'gray', 'zinc', 'neutral', 'stone',
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan',
        'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    public function testEveryColourUtilityNamesAPaletteTailwindShipsWith(): void
    {
        $css = \file_get_contents(\dirname(__DIR__, 2).'/assets/styles/dataflow.css');
        self::assertIsString($css);

        // `@apply` lines only: a comment mentioning a palette name is prose, not a class.
        \preg_match_all('/@apply ([^;]+);/', $css, $blocks);

        $foreign = [];

        foreach ($blocks[1] as $block) {
            foreach (\preg_split('/\s+/', \trim($block)) ?: [] as $utility) {
                $matched = \preg_match('/(?:^|:)(?:bg|text|border|ring|divide|from|to|via|outline|decoration|accent|caret|shadow|fill|stroke)-([a-z]+)-\d/', $utility, $parts);

                if (1 === $matched && !\in_array($parts[1], self::TAILWIND_PALETTES, true)) {
                    $foreign[] = $utility;
                }
            }
        }

        self::assertSame([], \array_values(\array_unique($foreign)), \sprintf(
            "These utilities name a palette Tailwind does not ship, so a consumer's build fails on a\nfile it did not write:\n  - %s",
            \implode("\n  - ", \array_unique($foreign)),
        ));
    }
}
