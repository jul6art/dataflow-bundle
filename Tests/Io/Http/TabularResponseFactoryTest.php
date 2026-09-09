<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Http;

use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;
use Jul6Art\DataflowBundle\Io\Writer\CsvWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The download factory.
 *
 * ⚠️ The assertion this file exists for is {@see self::testTheFilenameCannotSplitTheResponse()}.
 * The other five endpoints this factory replaces did not agree on it: three sanitised, one
 * hard-coded its filename, one did neither — and the value reaches the header from the database.
 */
#[CoversClass(TabularResponseFactory::class)]
final class TabularResponseFactoryTest extends TestCase
{
    public function testTheResponseCarriesTheWritersTypeAndExtension(): void
    {
        $response = new TabularResponseFactory()->stream(new CsvWriter(), ['a'], [['b']], 'contacts');

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename="contacts.csv"',
            $response->headers->get('Content-Disposition'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileBasenames(): iterable
    {
        yield 'CRLF injection' => ["contacts\r\nX-Injected: 1"];
        yield 'bare line feed' => ["contacts\nX-Injected: 1"];
        yield 'quote closing the parameter' => ['contacts"; x="1'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'semicolon starting a parameter' => ['contacts; filename*=utf-8\'\'evil'];
    }

    /**
     * ⚠️ A newline in this header splits the HTTP response, and everything after the split is
     * attacker-controlled. The rule is a drop, not an encode: a download filename has no need of
     * anything outside `[A-Za-z0-9_-]`, and a permissive rule would have to be right about every
     * user agent.
     */
    #[DataProvider('hostileBasenames')]
    public function testTheFilenameCannotSplitTheResponse(string $basename): void
    {
        $disposition = new TabularResponseFactory()
            ->stream(new CsvWriter(), [], [], $basename)
            ->headers->get('Content-Disposition');

        self::assertIsString($disposition);
        self::assertMatchesRegularExpression('/^attachment; filename="[A-Za-z0-9_-]*\.csv"$/', $disposition);
    }

    public function testTheConventionalBasenameJoinsItsPartsAndDatesThem(): void
    {
        self::assertSame(
            'contacts_acme_2026-09-09',
            TabularResponseFactory::basename(['contacts', 'acme'], new \DateTimeImmutable('2026-09-09')),
        );
    }

    /**
     * ⚠️ An empty part is dropped rather than leaving `contacts__2026-09-09`. A tenant slug can be
     * null, and a filename with a double separator reads like a bug to the person who receives it.
     */
    public function testAnEmptyPartDoesNotLeaveADoubleSeparator(): void
    {
        self::assertSame(
            'contacts_2026-09-09',
            TabularResponseFactory::basename(['contacts', ''], new \DateTimeImmutable('2026-09-09')),
        );
    }

    /**
     * The body is produced by the writer, and `sendContent()` is what runs it.
     *
     * ⚠️ **Two nested output buffers, and that is not belt-and-braces.** The factory calls
     * `ob_flush()` after each chunk, which pushes the innermost buffer's content up ONE level. With
     * a single buffer that level is PHP's own output, so the body escapes to stdout: the assertion
     * compares against an empty string and PHPUnit — configured with
     * `beStrictAboutOutputDuringTests` — additionally marks the test risky. Nesting a second buffer
     * gives the flush somewhere to go that the test can still read.
     *
     * The lesson generalises: a test that captures the output of code which flushes needs one more
     * buffer level than it looks like it needs.
     */
    public function testTheBodyIsWhatTheWriterProduced(): void
    {
        $response = new TabularResponseFactory()
            ->stream(new CsvWriter(), ['name'], [['Dupont']], 'contacts');

        ob_start();                 // outer: receives what the factory flushes
        ob_start();                 // inner: what `echo` writes into
        $response->sendContent();
        ob_end_flush();             // push whatever the last chunk left behind
        $body = ob_get_clean();

        self::assertSame("name\r\nDupont\r\n", $body);
    }
}
