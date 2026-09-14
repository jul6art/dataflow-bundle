<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import;

/**
 * What {@see TemplateBuilder} needs from a mapper that a plain {@see RowMapperInterface} does not
 * carry: one example value per field, and the values an enumerated field accepts.
 *
 * ## Optional, on purpose
 *
 * ⚠️ Every existing `RowMapperInterface` implementation stays valid without this. Folding
 * `exampleRow()` and `enumeratedValues()` into `RowMapperInterface` itself would force every mapper
 * ever written — most of which have neither an example worth showing nor a field an operator could
 * get wrong in an enumerable way — to answer two questions it may have nothing to say about.
 * `TemplateBuilder` checks `instanceof` and degrades to headers alone when a mapper does not
 * implement this, exactly as an unused `DuplicateResolverInterface` degrades to `Skip`.
 *
 * ## Why examples and allowed values, and nothing else
 *
 * A template's whole job is to remove the mapping screen for a user who starts from it: right
 * headers, in the right order, is what makes that automatic. **A worked example** is what turns a
 * header a user does not recognise ("SIRET") into one they can fill in on their own. **The
 * enumerated values** turn a support ticket ("what goes in `status`?") into a cell they can read,
 * because it appears right there in the file they are already editing — the cheapest reducer of
 * support cost this bundle can offer, and the reason lot 2.4 exists at all.
 */
interface TemplatableRowMapperInterface extends RowMapperInterface
{
    /**
     * One plausible value per field this mapper understands — the row a template shows filled in.
     *
     * ⚠️ A field this method does not mention renders as a BLANK cell, not an omitted column: the
     * template's columns are still exactly {@see RowMapperInterface::fields()}, in that order.
     *
     * @return array<string, string> field key => example cell value
     */
    public function exampleRow(): array;

    /**
     * The values a field accepts, for a field whose valid values are a closed, known set.
     *
     * ⚠️ Only for fields where this is actually true — a free-text name or an e-mail address has no
     * enumeration to offer, and an empty list here says exactly that; {@see TemplateBuilder} shows
     * nothing for a field this method does not mention.
     *
     * @return array<string, list<string>> field key => its accepted values, for enumerated fields only
     */
    public function enumeratedValues(): array;
}
