<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * No shipped template nests a Twig comment inside another one.
 *
 * ## The defect this exists for
 *
 * ⚠️ **Twig comments do not nest.** A usage example that annotates one of its lines with an inner
 * comment closes the outer block at the INNER terminator, and every line after it becomes literal
 * output — in the shipped partial that was thirteen lines of prose plus five `path()` calls,
 * printed onto the page above the stepper, in production-shaped markup.
 *
 * ⚠️ **`lint:twig` passes either way.** The file is valid Twig; it just means something else. Only
 * opening the screen showed it, which is why this check is static: it covers every template,
 * including the ones no render test has context for, and it costs a regular expression.
 *
 * ⚠️ Written after making the mistake twice — the second time inside the warning about the first.
 */
#[CoversNothing]
final class TwigCommentNestingTest extends TestCase
{
    public function testNoTemplateOpensACommentInsideAComment(): void
    {
        $offenders = [];

        foreach (self::templates() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            // Walk comment by comment: everything between an opening delimiter and the FIRST
            // terminator is one comment, and a second opener inside it is the defect.
            $offset = 0;

            while (false !== ($open = strpos($source, '{#', $offset))) {
                $close = strpos($source, '#}', $open + 2);

                if (false === $close) {
                    $offenders[] = \sprintf('%s: a comment is never closed', basename($path));

                    break;
                }

                $body = substr($source, $open + 2, $close - $open - 2);

                if (str_contains($body, '{#')) {
                    $offenders[] = \sprintf(
                        '%s line %d: a comment opens inside a comment, so the outer one ends here',
                        basename($path),
                        1 + substr_count(substr($source, 0, $open), "\n"),
                    );
                }

                $offset = $close + 2;
            }
        }

        self::assertSame([], $offenders, implode("\n  - ", ['', ...$offenders]));
    }

    /**
     * @return list<string>
     */
    private static function templates(): array
    {
        $root = \dirname(__DIR__, 2).'/Resources/views';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $templates = [];

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'twig' === $file->getExtension()) {
                $templates[] = $file->getPathname();
            }
        }

        self::assertNotSame([], $templates, 'No template found: the guard would pass vacuously.');

        return $templates;
    }
}
