<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Exception\ImportFailedException;
use Jul6Art\DataflowBundle\Exception\UnreadableFileException;
use Jul6Art\DataflowBundle\Import\HeaderInspection;
use Jul6Art\DataflowBundle\Import\HeaderInspector;
use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The import screens, in three steps — upload, mapping, run — and the downloadable model (ADR-0001).
 *
 * ⚠️ **No route is declared here.** The application routes the four actions under the names its menus already use,
 * with the import's key in the `_import` default — or one route for several imports, with an `{entity}`
 * parameter — and its definition names them back ({@see ImportRoutes}).
 *
 * ⚠️ **The import's access is checked on EVERY action**, the model and the mapping included: a screen that refuses
 * the run but hands out its model announces the feature to whoever cannot use it.
 *
 * ⚠️ **A refusal is said on the screen it concerns, with a 422** — a file that is too large on the upload screen,
 * a mapping with two columns for one field on the mapping screen — never as a flash after a redirect that loses the
 * file or the mapping that was being fixed.
 */
final class ImportController extends AbstractController
{
    public const string UPLOAD_TOKEN = 'dataflow_import_upload';

    public const string RUN_TOKEN = 'dataflow_import_run';

    private const string SESSION_PREFIX = 'dataflow.import.';

    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly UploadedImportStore $store,
        private readonly UploadGuard $guard,
        private readonly HeaderInspector $inspector,
        private readonly ImportPreviewBuilder $previews,
        private readonly TabularResponseFactory $responses,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $layout,
    ) {
    }

    /** Step 1: the file. A valid one is stored, and the mapping screen comes next. */
    public function upload(Request $request): Response
    {
        [$key, $definition, $context] = $this->open($request);

        if (!$request->isMethod('POST')) {
            return $this->screen('upload', $key, $definition, $context);
        }

        if (!$this->isCsrfTokenValid(self::UPLOAD_TOKEN, $request->request->getString('_token'))) {
            return $this->screen('upload', $key, $definition, $context, ['error' => 'dataflow.import.error.invalid_csrf'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('file');
        $refusal = $this->guard->refusalOf($file, $definition->maxBytes());

        if (null !== $refusal) {
            return $this->screen('upload', $key, $definition, $context, ['error' => $refusal], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            \assert($file instanceof UploadedFile);
            $name = $this->store->store($file);
        } catch (FileException) {
            return $this->screen('upload', $key, $definition, $context, ['error' => 'dataflow.import.error.upload_failed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->getSession()->set($this->sessionKey($key, $context), $name);

        return $this->redirectToRoute($definition->routes()->mapping, self::parameters($context), Response::HTTP_SEE_OTHER);
    }

    /** Step 2: each column of the file, offered every field; the preview when the import asks for one. */
    public function mapping(Request $request): Response
    {
        [$key, $definition, $context] = $this->open($request);
        $path = $this->store->pathOf($request->getSession()->get($this->sessionKey($key, $context)));

        if (null === $path) {
            return $this->redirectToRoute($definition->routes()->upload, self::parameters($context));
        }

        try {
            $inspection = $this->inspect($definition, $path);
        } catch (UnreadableFileException|\DomainException $refusal) {
            $this->forget($request, $key, $context, $path);

            return $this->screen('upload', $key, $definition, $context, ['error' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->mappingScreen($key, $definition, $context, $path, $inspection, $inspection->mapping);
    }

    /** Step 3: the run, then its report. */
    public function run(Request $request): Response
    {
        [$key, $definition, $context] = $this->open($request);
        $path = $this->store->pathOf($request->getSession()->get($this->sessionKey($key, $context)));

        if (null === $path) {
            return $this->screen('upload', $key, $definition, $context, ['error' => 'dataflow.import.error.file_expired'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $inspection = $this->inspect($definition, $path);
        } catch (UnreadableFileException|\DomainException $refusal) {
            $this->forget($request, $key, $context, $path);

            return $this->screen('upload', $key, $definition, $context, ['error' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mapping = self::mappingOf($request, $definition->fields());

        if (!$this->isCsrfTokenValid(self::RUN_TOKEN, $request->request->getString('_token'))) {
            return $this->mappingScreen($key, $definition, $context, $path, $inspection, $mapping, ['error' => 'dataflow.import.error.invalid_csrf']);
        }

        $refusal = self::refusalOf($mapping, $definition->requiredFields());

        if (null !== $refusal) {
            return $this->mappingScreen($key, $definition, $context, $path, $inspection, $mapping, $refusal);
        }

        $starting = new ImportStartingEvent($key, $definition, $context, $path, $mapping);
        $this->dispatcher->dispatch($starting);

        if (null !== $starting->response()) {
            return $starting->response();
        }

        try {
            $result = $definition->import($path, $mapping, $context);
        } catch (ImportFailedException $failure) {
            // ⚠️ A batch could not be written: the entity manager is closed, there is no "carry on". What the operator
            // must read first is how far the file went, then whether what was written was rolled back.
            $this->forget($request, $key, $context, $path);

            return $this->screen('report', $key, $definition, $context, [
                'report' => $failure->report(),
                'failed_at' => $failure->record(),
                'rolled_back' => $failure->wasRolledBack(),
            ]);
        } catch (UnreadableFileException|\DomainException $refusal) {
            // A refusal of the run is an input error, not an outage: back to the mapping, the file kept.
            return $this->mappingScreen($key, $definition, $context, $path, $inspection, $mapping, ['error' => $refusal->getMessage()]);
        }

        $this->forget($request, $key, $context, $path);

        return $this->screen('report', $key, $definition, $context, ['report' => $result->report] + $result->variables);
    }

    /**
     * The empty model: one header line, the field KEYS, in the reader's dialect.
     *
     * ⚠️ **Keys, not their labels**, and that is the only decision of this action. The header inspection compares a
     * header to a key after an alphanumeric normalisation: "Raison sociale" becomes `raisonsociale` and matches
     * nothing. A model in labels would reach the mapping screen with NO column recognised — every list to fill by
     * hand, on the file the screen itself just handed out.
     */
    public function template(Request $request): StreamedResponse
    {
        [$key, $definition] = $this->open($request);

        if (null === $definition->routes()->template) {
            throw $this->createNotFoundException(\sprintf('The import "%s" offers no model.', $key));
        }

        return $this->responses->stream($definition->writer(), $definition->fields(), [], TabularResponseFactory::basename(['template', $key]));
    }

    /**
     * The import the route names, its context, and the actor's right to it.
     *
     * @return array{string, ImportDefinitionInterface, ImportContext}
     */
    private function open(Request $request): array
    {
        $key = $request->attributes->get('_import') ?? $request->attributes->get('entity');

        if (!\is_string($key) || '' === $key) {
            throw new \LogicException('An import route carries the import\'s key, in an "_import" default or an "{entity}" parameter.');
        }

        $definition = $this->registry->get($key);
        /** @var array<string, mixed> $parameters */
        $parameters = $request->attributes->all('_route_params');
        $context = new ImportContext($this->getUser(), $parameters);

        foreach ($definition->access($context) as $access) {
            $this->denyAccessUnlessGranted($access->attribute, $access->subject);
        }

        return [$key, $definition, $context];
    }

    private function inspect(ImportDefinitionInterface $definition, string $path): HeaderInspection
    {
        return $this->inspector->inspect($this->inspector->peek($definition->reader(), $path), $definition->fields());
    }

    /**
     * @param array<int, string>   $mapping   the columns to show selected: the file's, or the one just submitted
     * @param array<string, mixed> $variables
     */
    private function mappingScreen(string $key, ImportDefinitionInterface $definition, ImportContext $context, string $path, HeaderInspection $inspection, array $mapping, array $variables = []): Response
    {
        // ⚠️ The preview reads the file with the mapping the screen SHOWS, not another one: rows read differently from
        // what the operator is about to confirm would be worse than no preview at all.
        $preview = $definition->preview() ? $this->previews->build($definition->reader(), $path, $mapping, $definition->duplicateResolver($context)) : null;

        return $this->screen('mapping', $key, $definition, $context, [
            'inspection' => $inspection,
            'selected' => $mapping,
            'preview' => $preview,
        ] + $variables, [] === $variables ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function screen(string $screen, string $key, ImportDefinitionInterface $definition, ImportContext $context, array $variables = [], int $status = Response::HTTP_OK): Response
    {
        $error = $variables['error'] ?? null;

        // ⚠️ `array_merge`, not `+`: the union keeps its LEFT side, and a default `error: null` would hide the refusal
        // the screen exists to show. The bundle's variables, then the definition's, then the screen's.
        return $this->render($definition->templates()[$screen] ?? '@Dataflow/import/'.$screen.'.html.twig', array_merge([
            'layout' => $this->layout,
            'import' => $key,
            'fields' => $definition->fields(),
            'required' => $definition->requiredFields(),
            'domain' => $definition->translationDomain(),
            'prefix' => $definition->translationPrefix(),
            'routes' => $definition->routes(),
            'route_parameters' => self::parameters($context),
            'back' => $definition->returnTo($context),
            'imports' => $this->openableImports($definition, $context),
            'max_bytes' => $definition->maxBytes(),
            'error' => null,
            // A key of this bundle is in its catalogue; any other is the definition's.
            'error_domain' => \is_string($error) && str_starts_with($error, 'dataflow.') ? 'dataflow' : $definition->translationDomain(),
            'error_parameters' => [],
        ], $definition->templateContext($context), $variables), new Response(status: $status));
    }

    /**
     * The imports this actor may open from the same place — the same upload route, one `{entity}` apart — in their
     * declared order; this one included.
     *
     * ⚠️ The same ROUTE, not every import: an index's constituents need the index's `{id}`, which the accounts'
     * import screen does not have — a link to it could not even be generated.
     *
     * ⚠️ Filtered by access, and that is a rule, not a courtesy: a visible link that answers 403 teaches twice, that
     * the feature exists and that it is not meant for you.
     *
     * @return list<array{key: string, domain: string, prefix: string, routes: ImportRoutes}>
     */
    private function openableImports(ImportDefinitionInterface $current, ImportContext $context): array
    {
        $imports = [];

        foreach ($this->registry->keys() as $key) {
            $definition = $this->registry->get($key);

            if ($definition->routes()->upload !== $current->routes()->upload) {
                continue;
            }

            foreach ($definition->access($context) as $access) {
                if (!$this->isGranted($access->attribute, $access->subject)) {
                    continue 2;
                }
            }

            $imports[] = ['key' => $key, 'domain' => $definition->translationDomain(), 'prefix' => $definition->translationPrefix(), 'routes' => $definition->routes()];
        }

        return $imports;
    }

    private function forget(Request $request, string $key, ImportContext $context, string $path): void
    {
        $request->getSession()->remove($this->sessionKey($key, $context));
        $this->store->discard($path);
    }

    /** One file per import AND per object: the constituents of two indices uploaded in two tabs do not swap files. */
    private function sessionKey(string $key, ImportContext $context): string
    {
        $parameters = self::parameters($context);
        ksort($parameters);

        return self::SESSION_PREFIX.$key.([] === $parameters ? '' : '.'.md5(http_build_query($parameters)));
    }

    /**
     * The route parameters the next screen needs: the object's `{id}`, the `{entity}` — never the `_import` default,
     * which every route of the import carries already.
     *
     * @return array<string, mixed>
     */
    private static function parameters(ImportContext $context): array
    {
        return array_filter($context->routeParameters, static fn (string $name): bool => !str_starts_with($name, '_'), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * The submitted mapping, keyed by column POSITION — the only identifier a column has: a file with two `email`
     * columns would lose one under a name. A field the import does not know is dropped.
     *
     * @param list<string> $fields
     *
     * @return array<int, string>
     */
    private static function mappingOf(Request $request, array $fields): array
    {
        $mapping = [];

        foreach ($request->request->all('mapping') as $position => $field) {
            if (is_numeric($position) && \is_string($field) && \in_array($field, $fields, true)) {
                $mapping[(int) $position] = $field;
            }
        }

        return $mapping;
    }

    /**
     * Why the mapping cannot run, or `null`.
     *
     * ⚠️ Two columns for one field are REFUSED, not settled: keeping the last one reads a column nobody chose.
     *
     * @param array<int, string> $mapping
     * @param list<string>       $required
     *
     * @return array{error: string, error_parameters?: array<string, string>}|null
     */
    private static function refusalOf(array $mapping, array $required): ?array
    {
        if ([] === $mapping) {
            return ['error' => 'dataflow.import.error.mapping_required'];
        }

        if (\count(array_unique($mapping)) !== \count($mapping)) {
            return ['error' => 'dataflow.import.error.mapping_duplicate'];
        }

        $missing = array_values(array_diff($required, $mapping));

        if ([] !== $missing) {
            return ['error' => 'dataflow.import.error.mapping_missing', 'error_parameters' => ['%fields%' => implode(', ', $missing)]];
        }

        return null;
    }
}
