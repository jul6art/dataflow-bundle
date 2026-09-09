<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\HeaderInspection;
use Jul6Art\DataflowBundle\Import\ImportReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use Twig\Environment;

/**
 * The shipped partials, RENDERED — not parsed, not linted.
 *
 * ## Why rendering is the assertion
 *
 * ⚠️ A lint pass proves the template compiles. What broke the screen this is extracted from was
 * not a syntax error: it was **thirty-four translation keys rendered raw**, in production, on every
 * panel. Nothing failed, because asking a translator for a key it does not have is not an error
 * anywhere — it returns the key.
 *
 * So the test loads the bundle's own English catalogue and asserts the output carries no
 * `dataflow.` substring at all. Any key the catalogue is missing shows up as itself, and that is
 * the one check no static scan can be talked out of.
 *
 * `strict_variables` is on in the test kernel, so an undefined variable is an exception rather
 * than an empty string — which is how a partial comes to render a button with no label.
 */
#[CoversNothing]
final class PartialsTest extends AbstractFunctionalTestCase
{
    public function testTheBuilderRendersEveryStepAndItsWiring(): void
    {
        $html = $this->render('@Dataflow/report/_builder.html.twig', [
            'entities' => ['App\\Entity\\Invoice' => ['label' => 'Invoices']],
            'fields_url' => '/reports/fields',
            'run_url' => '/reports/run',
            'export_url' => '/reports/export',
            'save_url' => '/reports/save',
            'load_url' => '/reports/load/{id}',
            'can_share' => true,
        ]);

        self::assertStringContainsString('data-controller="dataflow--report-builder"', $html);

        foreach (['fields', 'run', 'export', 'save'] as $endpoint) {
            self::assertStringContainsString(
                \sprintf('data-dataflow--report-builder-%s-url-value="/reports/%s"', $endpoint, $endpoint),
                $html,
            );
        }

        // ⚠️ The placeholder has to survive escaping: `{` and `}` are not HTML-escaped, but a
        // template that URL-encoded the value would ship `%7Bid%7D` and every load would 404.
        self::assertStringContainsString('load-url-value="/reports/load/{id}"', $html);

        foreach (['stepEntity', 'stepColumns', 'stepFilters', 'stepExport', 'previewBody', 'shareToggle'] as $target) {
            self::assertStringContainsString('-target="'.$target.'"', $html);
        }

        self::assertStringContainsString('Invoices', $html);
    }

    /**
     * ⚠️ **A partial's leading comment must not reach the page.** Twig comments do not nest, so an
     * inner `{# … #}` in a usage example closes the outer one at that point and every line after it
     * becomes literal output — thirteen lines of prose and five `path()` calls printed above the
     * stepper. `lint:twig` passes: the file is valid Twig, just not the Twig anybody meant. Only
     * opening the screen showed it, so this asserts the shape no eye has to check: the render starts
     * with the element, and nothing before it.
     */
    public function testNoPartialLeaksItsOwnDocumentation(): void
    {
        $rendered = [
            'report/_builder' => $this->render('@Dataflow/report/_builder.html.twig', [
                'entities' => [],
                'fields_url' => '/f',
                'run_url' => '/r',
                'export_url' => '/e',
                'save_url' => '/s',
                'load_url' => '/l/{id}',
                'can_share' => false,
            ]),
            'import/_mapper' => $this->render('@Dataflow/import/_mapper.html.twig', [
                'inspection' => new HeaderInspection(['Email'], [0 => 'email']),
                'fields' => ['email'],
            ]),
            'import/_report' => $this->render('@Dataflow/import/_report.html.twig', [
                'report' => new ImportReport(),
            ]),
        ];

        foreach ($rendered as $name => $html) {
            self::assertStringStartsWith('<', ltrim($html), $name.' leaks something before its first element.');
            self::assertStringNotContainsString('path(', $html, $name.' printed a Twig call as text.');
            self::assertStringNotContainsString('⚠️', $html, $name.' printed one of its own warnings.');
        }
    }

    /**
     * ⚠️ The whole point of this file. A key the catalogue lacks renders as itself.
     */
    public function testNoPartialLeaksARawTranslationKey(): void
    {
        $rendered = [
            $this->render('@Dataflow/report/_builder.html.twig', [
                'entities' => [],
                'fields_url' => '/f',
                'run_url' => '/r',
                'export_url' => '/e',
                'save_url' => '/s',
                'load_url' => '/l/{id}',
                'can_share' => false,
            ]),
            $this->render('@Dataflow/import/_mapper.html.twig', [
                'inspection' => new HeaderInspection(['Email', 'e-mail', 'salesforce_id'], [], [0, 1], [2], ['name']),
                'fields' => ['name', 'email'],
            ]),
            $this->render('@Dataflow/import/_report.html.twig', ['report' => $this->reportWithEverything()]),
        ];

        foreach ($rendered as $html) {
            self::assertStringNotContainsString('dataflow.', $html, 'A translation key reached the page.');
        }
    }

    /**
     * ⚠️ Keyed by INDEX. The implementation this replaces emitted `mapping[Email]`, so a file with
     * two columns both called `email` produced one field and the second column vanished — and the
     * import then read a plausible wrong column for every row of the file.
     */
    public function testTheMapperNamesItsFieldsByColumnIndex(): void
    {
        $html = $this->render('@Dataflow/import/_mapper.html.twig', [
            'inspection' => new HeaderInspection(['Email', 'e-mail'], [], [0, 1]),
            'fields' => ['email'],
        ]);

        self::assertStringContainsString('name="mapping[0]"', $html);
        self::assertStringContainsString('name="mapping[1]"', $html);
        self::assertStringNotContainsString('mapping[Email]', $html);
    }

    public function testTheMapperPreselectsAnUnambiguousSuggestion(): void
    {
        $html = $this->render('@Dataflow/import/_mapper.html.twig', [
            'inspection' => new HeaderInspection(['E-Mail'], [0 => 'email']),
            'fields' => ['name', 'email'],
        ]);

        self::assertStringContainsString('<option value="email" selected>', $html);
        self::assertStringContainsString('<option value="name" >', $html);
    }

    /**
     * ⚠️ A dry run says so on the page. Without it a panel announcing "1 240 imported" after a
     * preview is why somebody runs the real import a second time.
     */
    public function testADryRunIsAnnouncedAndARealRunIsNot(): void
    {
        $dry = $this->render('@Dataflow/import/_report.html.twig', ['report' => new ImportReport(dryRun: true)]);
        $real = $this->render('@Dataflow/import/_report.html.twig', ['report' => new ImportReport()]);

        self::assertStringContainsString('dry run', $dry);
        self::assertStringNotContainsString('dry run', $real);
    }

    /**
     * ⚠️ And the truncation too: showing only the sample implies the file had exactly a hundred
     * problems, when the count says otherwise.
     */
    public function testTheTruncationOfTheErrorSampleIsAnnounced(): void
    {
        $html = $this->render('@Dataflow/import/_report.html.twig', ['report' => $this->reportWithEverything()]);

        self::assertStringContainsString('Only the first', $html);
        self::assertStringContainsString('320', $html, 'The exact count, not the sample size.');
    }

    /**
     * ⚠️ The defect this pins: a consumer's mapper key printed RAW because the partial translated
     * every message in `dataflow`, where the key does not exist. A report legitimately mixes this
     * bundle's keys, the consumer's, and a validator's already-rendered text — so the domain comes
     * from the message's own first segment.
     */
    public function testAMessageIsTranslatedInTheDomainItsPrefixNames(): void
    {
        $report = new ImportReport();
        $report->recordError(2, 'dataflow.import.error.duplicate');
        $report->recordError(3, 'consumer.import.error.its_own_key');
        $report->recordError(4, 'email: This value is not a valid email address.');

        $html = $this->render('@Dataflow/import/_report.html.twig', ['report' => $report]);

        // This bundle's own key resolves against its own catalogue…
        self::assertStringContainsString('This record already exists.', $html);

        // …and a consumer's key is looked up in the CONSUMER's domain, which
        // `Tests/Fixtures/translations/consumer.en.xlf` provides. ⚠️ That fixture is what makes this
        // assertion discriminate: with no resolvable consumer domain, an unknown key comes back
        // unchanged whichever domain is used, so hard-coding `dataflow` passed too. Verified by
        // mutation, after the first version of this test let it through.
        self::assertStringContainsString('A refusal worded by the consumer.', $html);
        self::assertStringNotContainsString('consumer.import.error.its_own_key', $html);

        // …and a rendered validator message survives verbatim.
        self::assertStringContainsString('This value is not a valid email address.', $html);
    }

    private function reportWithEverything(): ImportReport
    {
        $report = new ImportReport();
        $report->recordImported();
        $report->recordSkipped();

        for ($i = 1; $i <= 320; ++$i) {
            $report->recordError($i, 'dataflow.import.error.duplicate');
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        $twig = $this->boot(withTwig: true)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render($template, $context);
    }
}
