<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Transformer;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a boolean as a word rather than as `1` / `0`.
 *
 * ## Why it takes a translator
 *
 * ⚠️ The version this replaces returned the literals `'Oui'` and `'Non'`. In one French
 * application that reads as a detail; in a published bundle it **imposes French on every
 * consumer**, and there is no way to opt out short of not using the transformer. Two keys and a
 * translator cost nothing and remove the whole question.
 *
 * ⚠️ **The domain is configurable and defaults to `dataflow`,** not to `messages`. A bundle that
 * hard-codes `messages` forces each of its consumers to break the rule this ecosystem enforces —
 * translations split by functional domain, `messages` never a dumping ground. `datatable-bundle`
 * had to gain the same key after the fact, and 165 lines of catalogue moved with it.
 *
 * ## And a boolean is not always a word
 *
 * ⚠️ This transformer is opt-in for that reason. A file another program parses wants `1` / `0`,
 * not `Yes` / `No` — a word is a presentation choice, and the interchange exports of this
 * ecosystem deliberately keep their headers and values machine-readable. Register it for the
 * reports a person reads; leave it out of the ones a system consumes.
 */
final readonly class BoolValueTransformer implements ValueTransformerInterface
{
    public const string KEY_TRUE = 'dataflow.value.yes';
    public const string KEY_FALSE = 'dataflow.value.no';

    public function __construct(
        private TranslatorInterface $translator,
        private string $translationDomain = 'dataflow',
    ) {
    }

    #[\Override]
    public function supports(mixed $value): bool
    {
        return \is_bool($value);
    }

    #[\Override]
    public function transform(mixed $value): string
    {
        \assert(\is_bool($value));

        return $this->translator->trans(
            $value ? self::KEY_TRUE : self::KEY_FALSE,
            [],
            $this->translationDomain,
        );
    }
}
