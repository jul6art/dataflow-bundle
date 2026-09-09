<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;

/**
 * Matches a file's header row against the fields a {@see RowMapperInterface} understands.
 *
 * ## Why matching is normalised, and why the table is explicit
 *
 * A file exported from one system says `First Name`, another says `first_name`, a third says
 * `firstName`, and a French one says `Prénom`. Comparing raw strings makes the mapping screen a
 * chore that the user has to redo for every file, so headers and field keys are both reduced to
 * lower-case alphanumerics before they are compared.
 *
 * ⚠️ **Accents are folded through an explicit table, not `iconv('UTF-8', 'ASCII//TRANSLIT')`.**
 * That transliteration depends on the process locale: under `C` it produces `'e` for `é` on glibc
 * and `?` on musl, so the same file maps on the developer's machine and fails in the container. A
 * lookup table is twenty lines and gives the same answer everywhere.
 *
 * ⚠️ **And the table carries both cases, so that nothing here needs `mb_strtolower`.** Requiring
 * `ext-mbstring` for one call would put an extension in the way of installing this bundle, and
 * `strtolower` alone leaves `É` untouched — it works on bytes, and a multi-byte letter is not one.
 *
 * ⚠️ **A collision is reported, never resolved.** Two columns reducing to the same field leave
 * BOTH out of the suggestion and land in `ambiguous`, for the screen to ask about. Letting the last
 * one win is what a name-keyed map does by accident, and the resulting import reads a plausible
 * wrong column — the kind of defect that is found by a customer, months later, in their data.
 */
final readonly class HeaderInspector
{
    /**
     * Latin-1 and Latin Extended-A characters a European header actually contains, in both cases.
     *
     * @var array<string, string>
     */
    private const array FOLD = [
        'à' => 'a', 'À' => 'a', 'á' => 'a', 'Á' => 'a', 'â' => 'a', 'Â' => 'a', 'ã' => 'a', 'Ã' => 'a',
        'ä' => 'a', 'Ä' => 'a', 'å' => 'a', 'Å' => 'a', 'æ' => 'ae', 'Æ' => 'ae', 'ç' => 'c', 'Ç' => 'c',
        'è' => 'e', 'È' => 'e', 'é' => 'e', 'É' => 'e', 'ê' => 'e', 'Ê' => 'e', 'ë' => 'e', 'Ë' => 'e',
        'ì' => 'i', 'Ì' => 'i', 'í' => 'i', 'Í' => 'i', 'î' => 'i', 'Î' => 'i', 'ï' => 'i', 'Ï' => 'i',
        'ñ' => 'n', 'Ñ' => 'n', 'ò' => 'o', 'Ò' => 'o', 'ó' => 'o', 'Ó' => 'o', 'ô' => 'o', 'Ô' => 'o',
        'õ' => 'o', 'Õ' => 'o', 'ö' => 'o', 'Ö' => 'o', 'ø' => 'o', 'Ø' => 'o', 'œ' => 'oe', 'Œ' => 'oe',
        'ù' => 'u', 'Ù' => 'u', 'ú' => 'u', 'Ú' => 'u', 'û' => 'u', 'Û' => 'u', 'ü' => 'u', 'Ü' => 'u',
        'ý' => 'y', 'Ý' => 'y', 'ÿ' => 'y', 'Ÿ' => 'y', 'š' => 's', 'Š' => 's', 'ž' => 'z', 'Ž' => 'z',
        'ß' => 'ss',
    ];

    /**
     * Reads the first record of a file and nothing more.
     *
     * @return list<string>
     *
     * @throws UnreadableFileException when the file has no record at all
     */
    public function peek(TabularReaderInterface $reader, string $filePath): array
    {
        foreach ($reader->read($filePath) as $cells) {
            // The generator is abandoned here: the rest of the file is never read, and the reader's
            // `finally` closes the handle when this frame drops the last reference to it.
            return $cells;
        }

        throw UnreadableFileException::empty();
    }

    /**
     * @param list<string> $headers the file's header row
     * @param list<string> $fields  the field keys the mapper understands
     */
    public function inspect(array $headers, array $fields): HeaderInspection
    {
        $byNormalisedField = [];

        foreach ($fields as $field) {
            $byNormalisedField[self::normalise($field)] = $field;
        }

        // First pass: which columns claim which field. A field claimed twice is a collision, and
        // that cannot be known until every column has been looked at — hence two passes.
        $claims = [];
        $unknown = [];

        foreach ($headers as $index => $header) {
            $normalised = self::normalise($header);
            $field = $byNormalisedField[$normalised] ?? null;

            if ('' === $normalised || null === $field) {
                $unknown[] = $index;

                continue;
            }

            $claims[$field][] = $index;
        }

        $mapping = [];
        $ambiguous = [];

        foreach ($claims as $field => $indices) {
            if (1 === \count($indices)) {
                $mapping[$indices[0]] = $field;

                continue;
            }

            foreach ($indices as $index) {
                $ambiguous[] = $index;
            }
        }

        \ksort($mapping);
        \sort($ambiguous);

        return new HeaderInspection(
            $headers,
            $mapping,
            $ambiguous,
            $unknown,
            \array_values(\array_diff($fields, \array_values($mapping))),
        );
    }

    /**
     * `  Prénom (obligatoire) ` and `prenom_obligatoire` both become `prenomobligatoire`.
     */
    private static function normalise(string $value): string
    {
        // ASCII first, then the table: `strtolower` works on bytes, so it lowers `A` and leaves
        // `É` alone — which is exactly the half the table then handles, in both cases.
        $folded = \strtr(\strtolower(\trim($value)), self::FOLD);

        return (string) \preg_replace('/[^a-z0-9]+/', '', $folded);
    }
}
