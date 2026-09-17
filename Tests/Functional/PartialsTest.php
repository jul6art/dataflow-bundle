<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\HeaderInspection;
use Jul6Art\DataflowBundle\Import\ImportReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
     * ⚠️ The number shown is the CONFIGURED ceiling, not a literal in the template — proven by
     * changing the configuration and checking the page changes with it. A warning naming a
     * different number than the one `ReportRunner` actually enforces would be worse than none: an
     * operator who exports without being warned hits a silent truncation and has to notice, on
     * their own, that the file came back short.
     */
    public function testTheExportPanelWarnsWithTheConfiguredRowLimit(): void
    {
        $html = $this->render(
            '@Dataflow/report/_builder.html.twig',
            [
                'entities' => [],
                'fields_url' => '/f',
                'run_url' => '/r',
                'export_url' => '/e',
                'save_url' => '/s',
                'load_url' => '/l/{id}',
                'can_share' => false,
            ],
            bundleConfig: ['limits' => ['export_rows' => 12345]],
        );

        self::assertStringContainsString('12,345', $html);
    }

    /**
     * ⚠️ The two selects of a filter row become select2 widgets, and the identifier that makes it
     * happen is the APPLICATION's. The bundle cannot know it: it is derived from where the consumer
     * put the controller file. Defaulted to what the three applications of this ecosystem produce.
     */
    public function testTheBuilderPublishesTheApplicationsSelect2Identifier(): void
    {
        $html = $this->render('@Dataflow/report/_builder.html.twig', self::context());

        self::assertStringContainsString('select2-identifier-value="ui--select2"', $html);
    }

    public function testTheSelect2IdentifierIsConfigurable(): void
    {
        $html = $this->render(
            '@Dataflow/report/_builder.html.twig',
            self::context(),
            ['select2_identifier' => 'widgets--picker'],
        );

        self::assertStringContainsString('select2-identifier-value="widgets--picker"', $html);
    }

    /**
     * ⚠️ An EMPTY identifier is a consumer saying "plain selects, thank you", and it must render a
     * complete screen rather than a broken one — a field picker listing forty paths is usable
     * unstyled. Empty is also what the controller reads as "do nothing", so the two ends agree.
     */
    public function testAnEmptySelect2IdentifierLeavesPlainSelects(): void
    {
        $html = $this->render(
            '@Dataflow/report/_builder.html.twig',
            self::context(),
            ['select2_identifier' => ''],
        );

        self::assertStringContainsString('select2-identifier-value=""', $html);
    }

    /**
     * ⚠️ Without `symfony/security-csrf` CONFIGURED, the builder must still render — a compile
     * error here would break the whole partial over a package the application deliberately does
     * not have, exactly the hazard `DataflowCsrfExtension`'s own docblock names.
     */
    public function testWithNoCsrfManagerTheSaveAttributeIsEmptyNotAnError(): void
    {
        $html = $this->render('@Dataflow/report/_builder.html.twig', [
            'entities' => [],
            'fields_url' => '/f',
            'run_url' => '/r',
            'export_url' => '/e',
            'save_url' => '/s',
            'load_url' => '/l/{id}',
            'can_share' => false,
        ]);

        self::assertStringContainsString('save-csrf-value=""', $html);
    }

    /**
     * ⚠️ The end-to-end proof: with `symfony/security-csrf` actually configured, the attribute
     * carries a REAL, validatable token — not merely a non-empty string. A test only checking
     * "not empty" would still pass if the wiring minted garbage.
     */
    public function testWithACsrfManagerTheSaveAttributeCarriesARealToken(): void
    {
        $container = $this->boot(withTwig: true, extraConfig: [
            'framework' => [
                'csrf_protection' => true,
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file', 'handler_id' => null],
            ],
        ]);

        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        // ⚠️ `SessionTokenStorage` reads the CURRENT request off the stack and starts a session on
        // it — neither exists outside a real HTTP request cycle, which a bare `$twig->render()`
        // call is not. Pushing one is what a real request would already have done by the time this
        // partial renders.
        $requestStack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        $sessionFactory = $container->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);

        $request = Request::create('/');
        $request->setSession($sessionFactory->createSession());
        $requestStack->push($request);

        $html = $twig->render('@Dataflow/report/_builder.html.twig', [
            'entities' => [],
            'fields_url' => '/f',
            'run_url' => '/r',
            'export_url' => '/e',
            'save_url' => '/s',
            'load_url' => '/l/{id}',
            'can_share' => false,
        ]);

        self::assertMatchesRegularExpression('/save-csrf-value="[^"]+"/', $html);

        preg_match('/save-csrf-value="([^"]+)"/', $html, $matches);
        $token = $matches[1] ?? '';
        self::assertNotSame('', $token);

        $csrfTokenManager = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrfTokenManager);
        self::assertTrue($csrfTokenManager->isTokenValid(
            new CsrfToken('dataflow_report_builder', $token),
        ));
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
        // ⚠️ Sans cet appel, le panneau « updated » — conditionnel, il ne s'affiche que si
        // `report.updated > 0` — ne serait JAMAIS rendu par ce test, et une clef de traduction
        // manquante pour lui passerait inaperçue : exactement le défaut que ce fichier existe pour
        // attraper.
        $report->recordUpdated();
        $report->recordSkipped();

        for ($i = 1; $i <= 320; ++$i) {
            $report->recordError($i, 'dataflow.import.error.duplicate');
        }

        return $report;
    }

    /**
     * The minimum a builder needs to render.
     *
     * @return array<string, mixed>
     */
    private static function context(): array
    {
        return [
            'entities' => [],
            'fields_url' => '/f',
            'run_url' => '/r',
            'export_url' => '/e',
            'save_url' => '/s',
            'load_url' => '/l/{id}',
            'can_share' => false,
        ];
    }

    /**
     * @param array<string, mixed>                $context
     * @param array<string, mixed>                $bundleConfig
     * @param array<string, array<string, mixed>> $extraConfig
     */
    private function render(string $template, array $context, array $bundleConfig = [], array $extraConfig = []): string
    {
        $twig = $this->boot(bundleConfig: $bundleConfig, withTwig: true, extraConfig: $extraConfig)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render($template, $context);
    }
}
