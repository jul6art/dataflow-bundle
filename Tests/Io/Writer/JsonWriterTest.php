<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Io\Writer;

use Jul6Art\DataflowBundle\Io\Writer\JsonWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The JSON writer.
 *
 * ⚠️ Every assertion decodes the output rather than matching a string. The document is assembled by
 * hand — braces and commas — precisely so that no complete object is ever built in memory, and the
 * only property worth checking about hand-assembled structure is that it **parses**.
 */
#[CoversClass(JsonWriter::class)]
final class JsonWriterTest extends TestCase
{
    public function testTheDocumentParsesAndCarriesColumnsAndRows(): void
    {
        $decoded = $this->writeAndDecode(['name', 'total'], [['Dupont', '100.00'], ['Martin', '-50.00']]);

        self::assertSame(['name', 'total'], $decoded['columns']);
        self::assertSame([['Dupont', '100.00'], ['Martin', '-50.00']], $decoded['rows']);
    }

    public function testAnEmptyRowSourceStillParses(): void
    {
        $decoded = $this->writeAndDecode(['a'], []);

        self::assertSame(['a'], $decoded['columns']);
        self::assertSame([], $decoded['rows']);
    }

    /**
     * ⚠️ No formula guard here, and it is asserted rather than assumed: a spreadsheet never opens
     * this file, so prefixing a value would corrupt the payload of the only consumer there is.
     */
    public function testValuesAreNotNeutralisedBecauseNoSpreadsheetReadsThis(): void
    {
        $decoded = $this->writeAndDecode([], [['=1+1']]);

        self::assertSame([['=1+1']], $decoded['rows']);
    }

    /**
     * ⚠️ Accents and slashes stay readable: a JSON export is read by people as often as by
     * programs, and `é` in a file someone opens is a needless obstacle.
     */
    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $raw = $this->write([], [['prénom', 'http://example.test/a']]);

        self::assertStringContainsString('prénom', $raw);
        self::assertStringContainsString('http://example.test/a', $raw);
    }

    /**
     * ⚠️ A row that cannot be encoded must FAIL, not silently vanish. With `json_encode`'s default
     * a malformed UTF-8 byte — the realistic case being a legacy column — returns `false`, which
     * would emit the empty string and produce a syntactically valid document missing a row. That
     * is the worst possible outcome, and this test is what forbids it.
     */
    public function testAnUnencodableRowThrowsRatherThanDisappearing(): void
    {
        $this->expectException(\JsonException::class);

        $this->write([], [["\xB1\x31"]]);
    }

    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     *
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     */
    private function writeAndDecode(array $header, iterable $rows): array
    {
        /** @var array{columns: list<string>, rows: list<list<scalar|null>>} $decoded */
        $decoded = json_decode($this->write($header, $rows), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param list<string>                            $header
     * @param iterable<array<array-key, scalar|null>> $rows
     */
    private function write(array $header, iterable $rows): string
    {
        $out = '';

        new JsonWriter()->write($header, $rows, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }
}
