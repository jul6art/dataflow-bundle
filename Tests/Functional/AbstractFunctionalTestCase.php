<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class AbstractFunctionalTestCase extends TestCase
{
    private ?TestKernel $kernel = null;

    /**
     * The top of the exception-handler stack before anything of ours ran, so tearDown knows how far
     * to unwind. Captured in setUp rather than at boot: a test may boot more than once.
     */
    private mixed $handlerBeforeBoot = null;

    /** Computed once per process; see {@see self::sourceFingerprint()}. */
    private static ?int $sourceFingerprint = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->handlerBeforeBoot = self::currentExceptionHandler();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        $this->restoreExceptionHandlers();

        parent::tearDown();
    }

    /**
     * Boots a kernel and returns its container.
     *
     * The build directory is keyed on the scenario so two configurations never share a compiled
     * container — a stale one silently invalidates the assertions, and it is the single most
     * confusing failure mode of a bundle test suite.
     *
     * ⚠️ **And the key includes a fingerprint of the bundle's own source**, which it did not at
     * first. Keyed on the scenario alone, editing `services.yaml` and re-running gave the container
     * compiled from the PREVIOUS version: the kernel runs with `debug: false`, so it never checks
     * whether its cache is fresh. That produced a green run for a defect that had just been
     * introduced — and then a red one, minutes later, for code that had not changed. Exactly the
     * failure mode the paragraph above warns about, in the harness that warns about it.
     *
     * @param array<string, mixed> $bundleConfig
     */
    final protected function boot(
        string $environment = 'test',
        array $bundleConfig = [],
        bool $withOrm = false,
    ): ContainerInterface {
        $uniqueId = substr(md5(serialize([
            $bundleConfig,
            $withOrm,
            self::sourceFingerprint(),
        ])), 0, 12);

        // Arguments nommés : une brique absente retire son paramètre du kernel, et un appel
        // positionnel se décalerait silencieusement.
        $this->kernel = new TestKernel(
            $environment,
            $bundleConfig,
            withOrm: $withOrm,
            uniqueId: $uniqueId,
        );
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }

    /**
     * Pops every exception handler booting the kernel pushed, and no more.
     *
     * `FrameworkBundle::boot()` calls `ErrorHandler::register()`, which pushes a handler and never
     * removes it. Booting is our own side effect, so PHPUnit is right to report the leak —
     * `beStrictAboutChangesToGlobalState` is on.
     *
     * ⚠️ The obvious version — pop once if the top handler is a Symfony `ErrorHandler` — is what
     * Symfony's own `KernelTestCase` does, and it is not enough here: on the **lowest** dependency
     * set the leaked handler is not that shape, so nothing was popped and all 26 filter tests came
     * back risky. On the highest set they were green, which is the worst kind of difference to
     * chase. So the stack is drained back to the handler that was installed before boot, whatever
     * either version happens to push.
     *
     * The bound is a safety net, not a limit: if the handler recorded before boot never reappears —
     * something replaced the stack rather than pushing onto it — this must stop rather than spin.
     */
    private function restoreExceptionHandlers(): void
    {
        for ($i = 0; $i < 16; ++$i) {
            if (self::currentExceptionHandler() === $this->handlerBeforeBoot) {
                return;
            }

            restore_exception_handler();
        }
    }

    /**
     * The newest modification time across the bundle's own source, computed once per process.
     *
     * Everything that can change how the container compiles is in here: `Resources/config`, the
     * extension and its passes, the bundle class, and every service class — an argument renamed in
     * a constructor changes the wiring as surely as a line of YAML does. Tests are excluded: they
     * cannot affect compilation, and including them would invalidate the cache on every edit.
     *
     * A fingerprint rather than the cache-freshness machinery, because `debug: true` is not
     * available here — it installs an error handler and never removes it, which is global state
     * PHPUnit is right to report.
     */
    private static function sourceFingerprint(): int
    {
        if (null !== self::$sourceFingerprint) {
            return self::$sourceFingerprint;
        }

        $root = \dirname(__DIR__, 2);
        $newest = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    $name = $file->getFilename();

                    if ($file->isDir()) {
                        return !\in_array($name, ['vendor', 'Tests', 'var', '.git', '.github'], true);
                    }

                    return \in_array($file->getExtension(), ['php', 'yaml', 'yml', 'xml'], true);
                },
            ),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $newest = max($newest, (int) $file->getMTime());
        }

        return self::$sourceFingerprint = $newest;
    }

    /**
     * Reads the top of the exception-handler stack without changing it: `set_exception_handler()`
     * returns the previous handler, and `restore_exception_handler()` undoes the push it just made.
     */
    private static function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
