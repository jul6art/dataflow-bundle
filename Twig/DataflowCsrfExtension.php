<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Twig;

use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Mints the ONE CSRF token the shipped report builder partial needs (lot 2.9) — the same reasoning
 * `datatable-bundle`'s `DataTableCsrfExtension` already established for its own partials, adapted
 * to mint the token itself rather than only its id.
 *
 * ```twig
 * data-{{ stimulus }}-save-csrf-value="{{ dataflow_csrf_token() }}"
 * ```
 *
 * ⚠️ **`getFunctions()`, not `#[AsTwigFunction]`.** The attribute is processed by TwigBundle's OWN
 * autoconfiguration, which needs `autoconfigure: true` on the service — off, everywhere in this
 * file, for every reason its own top comment gives. Tagged `twig.extension` by hand instead, this
 * class has to register its function the classic way, or `Environment::addExtension()` receives
 * something that is not an `ExtensionInterface` and the container fails to build the Twig service
 * at all — found by this bundle's own partial-rendering tests, not assumed.
 *
 * ⚠️ **The token, not Twig's own `csrf_token()`, and that is the point.** `symfony/security-csrf`
 * is a `suggest` of this bundle, not a `require` — calling the GLOBAL `csrf_token()` function in
 * the shipped partial would be a Twig COMPILE error the day it is absent, since that function does
 * not exist without the package. Minting it here, tolerant of `$csrfTokenManager` being `null`,
 * keeps the partial rendering — with an empty value the Stimulus controller already treats as "no
 * token to send" — exactly as `ReportBuilderCsrfChecker` treats an absent manager as "nothing to
 * check".
 *
 * ⚠️ **Only `save` gets a token, and that is not an oversight.** `run` and `export` never let a
 * forged cross-origin request read anything back — no `Access-Control-Allow-Origin` means the
 * response body is invisible to the attacker's page, whichever format it downloads. `save` creates
 * or, on a guessed sequential id, OVERWRITES a report in the actor's own account: a nuisance a
 * token stops, not a divulgation the other two routes were ever exposed to.
 */
final class DataflowCsrfExtension extends AbstractExtension
{
    public function __construct(
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $tokenId = 'dataflow_report_builder',
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dataflow_csrf_token', $this->csrfToken(...)),
        ];
    }

    public function csrfToken(): string
    {
        return $this->csrfTokenManager?->getToken($this->tokenId)->getValue() ?? '';
    }
}
