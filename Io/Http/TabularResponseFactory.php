<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Io\Http;

use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a writer and a row source into a streamed download.
 *
 * ## What it absorbs
 *
 * Before the extraction, five export endpoints each carried their own copy of the same twelve
 * lines: the `StreamedResponse`, the `ob_flush`, the content type, and the `Content-Disposition`.
 * Three of the five sanitised the filename against CR/LF injection, one hard-coded its filename,
 * and one did neither. That asymmetry is the argument for a factory — not the twelve lines.
 *
 * ⚠️ **The filename is sanitised, and it is a security control, not tidiness.** A tenant slug or a
 * report name reaches this header from the database. A newline in it splits the HTTP response, and
 * everything after the split is attacker-controlled. Anything outside `[A-Za-z0-9_-]` is dropped
 * rather than encoded: a download filename has no need of the rest, and a permissive rule here
 * would have to be right about every user agent.
 *
 * ## Flushing
 *
 * ⚠️ `ob_flush()` and `flush()` are called per chunk and both are silenced, on purpose. Whether an
 * output buffer exists depends on the SAPI and on `output_buffering`; calling `ob_flush()` without
 * one raises a notice, and this bundle's own PHPUnit configuration fails on notices. The silence
 * is the portable form, and the alternative — probing `ob_get_level()` on every chunk — buys
 * nothing.
 */
final readonly class TabularResponseFactory
{
    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     * @param string                                  $basename the human part of the filename, before
     *                                                           sanitisation and before the extension
     */
    public function stream(
        TabularWriterInterface $writer,
        array $header,
        iterable $rows,
        string $basename,
    ): StreamedResponse {
        $response = new StreamedResponse(static function () use ($writer, $header, $rows): void {
            $writer->write($header, $rows, static function (string $chunk): void {
                echo $chunk;
                @\ob_flush();
                @\flush();
            });
        });

        $response->headers->set('Content-Type', $writer->contentType());
        $response->headers->set('Content-Disposition', \sprintf(
            'attachment; filename="%s.%s"',
            self::sanitize($basename),
            $writer->fileExtension(),
        ));

        return $response;
    }

    /**
     * Builds the conventional `<subject>_<tenant>_<date>` basename, so that two exports of the same
     * product do not arrive named by two conventions.
     *
     * Each part is sanitised, and an empty part is dropped rather than leaving a double separator.
     *
     * @param list<string> $parts
     */
    public static function basename(array $parts, ?\DateTimeImmutable $on = null): string
    {
        $parts[] = ($on ?? new \DateTimeImmutable())->format('Y-m-d');

        $clean = [];

        foreach ($parts as $part) {
            $sanitized = self::sanitize($part);

            if ('' !== $sanitized) {
                $clean[] = $sanitized;
            }
        }

        return \implode('_', $clean);
    }

    /**
     * ⚠️ Never `urlencode()` here: a percent-escape in an unquoted `filename` parameter is not
     * decoded by every user agent, and the ones that do not show the escape to the user.
     */
    private static function sanitize(string $value): string
    {
        return \preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
    }
}
