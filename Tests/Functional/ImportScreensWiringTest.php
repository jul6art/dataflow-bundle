<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\Web\ImportController;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The import screens are off until an application turns them on: it keeps its own controllers until then.
 */
#[CoversNothing]
final class ImportScreensWiringTest extends AbstractFunctionalTestCase
{
    public function testTheScreensAreOffByDefault(): void
    {
        $container = $this->boot(withTwig: true);

        self::assertFalse($container->has(ImportController::class));
        self::assertFalse($container->hasParameter('dataflow.import.directory'));
    }

    public function testTurnedOnTheyGetTheirControllerAndTheirSettings(): void
    {
        $container = $this->boot(bundleConfig: ['import' => ['enabled' => true, 'max_bytes' => 1024]], withTwig: true);

        self::assertTrue($container->has(ImportController::class));
        self::assertSame(1024, $container->getParameter('dataflow.import.max_bytes'));
        self::assertSame('admin/layout.html.twig', $container->getParameter('dataflow.import.layout'));
        $directory = $container->getParameter('dataflow.import.directory');
        self::assertIsString($directory);
        self::assertStringEndsWith('/var/import', $directory);
    }

    public function testWithoutTwigTheyAreRefusedAtCompileTimeNotOnTheFirstRequest(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('symfony/twig-bundle');

        $this->boot(bundleConfig: ['import' => ['enabled' => true]]);
    }
}
