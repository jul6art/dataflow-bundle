<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * The workbook a mapping screen makes unnecessary: right headers, in the right order, with one row
 * showing how they are filled in.
 *
 * ```php
 * $builder = new TemplateBuilder();
 *
 * return $this->responses->stream(
 *     $writer,
 *     $builder->header($mapper),
 *     $builder->rows($mapper),
 *     TabularResponseFactory::basename(['template', 'customers']),
 * );
 * ```
 *
 * ## Why the columns never change shape
 *
 * ⚠️ **`header()` and `rows()` both walk {@see RowMapperInterface::fields()}, in that order, always.**
 * A template whose column count depended on how much a mapper had to say about each field would be
 * a second, silently different contract from the one `HeaderInspector` matches against — a user who
 * fills in the template and a user who fills in a blank screen must land on identical columns.
 *
 * ## Two things this deliberately does NOT do
 *
 * ⚠️ **No second sheet, no cell comment, no data-validation dropdown.** OpenSpout does not expose
 * either of the last two, and reproducing them in CSV is meaningless — this bundle emits ONE
 * document through the writer the caller already has, in whichever of the two formats they choose.
 * The enumerated values a field accepts are said in its HEADER TEXT instead: `status (active/inactive)`
 * is legible in a text editor, in Excel, and in a spreadsheet app that supports neither of the two
 * richer mechanisms — a plain answer over a prettier one two of this bundle's three consumers could
 * not render anyway.
 *
 * ⚠️ **A mapper that is not {@see TemplatableRowMapperInterface} still gets a template** — headers
 * alone, one blank row. A template with only the columns right is still strictly better than a
 * mapping screen with nothing filled in, and degrading rather than refusing is what makes adding the
 * richer interface later, to a mapper already in production, entirely additive.
 */
final readonly class TemplateBuilder
{
    /**
     * @return list<string>
     */
    public function header(RowMapperInterface $mapper): array
    {
        $enumerated = $mapper instanceof TemplatableRowMapperInterface ? $mapper->enumeratedValues() : [];

        return \array_map(
            static function (string $field) use ($enumerated): string {
                $values = $enumerated[$field] ?? [];

                if ([] === $values) {
                    return $field;
                }

                return \sprintf('%s (%s)', $field, \implode('/', $values));
            },
            $mapper->fields(),
        );
    }

    /**
     * One example row — blank for a field the mapper has nothing to say about, and blank for
     * every field when the mapper is not {@see TemplatableRowMapperInterface} at all.
     *
     * @return list<array<array-key, scalar|null>>
     */
    public function rows(RowMapperInterface $mapper): array
    {
        $example = $mapper instanceof TemplatableRowMapperInterface ? $mapper->exampleRow() : [];

        return [
            \array_map(
                static fn (string $field): string => $example[$field] ?? '',
                $mapper->fields(),
            ),
        ];
    }
}
