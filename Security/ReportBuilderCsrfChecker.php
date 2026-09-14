<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The one line a project's OWN "save" controller needs (lot 2.9) — a bundle cannot ship the
 * controller itself (the entity, the route, the firewall are the project's), only the check.
 *
 * ```php
 * if (!$this->csrf->isValid($request)) {
 *     throw new AccessDeniedHttpException('invalid_csrf_token');
 * }
 * ```
 *
 * ⚠️ **Tolerant of absence, on purpose.** Without `symfony/security-csrf` configured, this answers
 * `true` — the partial could not have minted a token to check against either, and refusing every
 * save would break the feature over a package the application deliberately does not have. The same
 * trade `DatatablePreferenceController` already makes for its own writes.
 *
 * ⚠️ **Reads `_dataflow_csrf_token` from the REQUEST body, not a header.** `save` posts
 * `multipart/form-data` — building a JSON body just to carry one token would be a second wire shape
 * for no reason; the shipped Stimulus controller appends it as a form field, next to `name` and
 * `shareScope`.
 */
final readonly class ReportBuilderCsrfChecker
{
    public function __construct(
        private ?CsrfTokenManagerInterface $csrfTokenManager,
        private string $tokenId = 'dataflow_report_builder',
    ) {
    }

    public function isValid(Request $request): bool
    {
        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return true;
        }

        $token = $request->request->get('_dataflow_csrf_token');

        return $this->csrfTokenManager->isTokenValid(
            new CsrfToken($this->tokenId, \is_string($token) ? $token : ''),
        );
    }
}
