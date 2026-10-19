<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/pages/ReviewerLinkHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerLinkHandler
 *
 * @brief Answers `invitation/accept` for the links sent to reviewers: every
 *        click within the validity period signs the reviewer in and opens the
 *        review; anything else leads to the login form, never to a 404.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\pages;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkDecision;
use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkStore;
use APP\plugins\generic\ojsbrReviewerMagicLink\OjsbrReviewerMagicLinkPlugin;
use APP\template\TemplateManager;
use Carbon\Carbon;
use PKP\context\Context;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\reviewAssignment\ReviewAssignment;

class ReviewerLinkHandler extends Handler
{
    public function __construct(public OjsbrReviewerMagicLinkPlugin $plugin, public LinkStore $store)
    {
        parent::__construct();
    }

    /**
     * The link of the e-mail.
     *
     * @param array $args
     * @param Request $request
     */
    public function accept($args, $request): void
    {
        $context = $request->getContext();
        $invitationId = (int) $request->getUserVar('id');
        $key = (string) $request->getUserVar('key');

        // Mail scanners (Microsoft Safe Links among them) check the link with a
        // HEAD request a moment before the person clicks it. That request must
        // not sign anybody in nor change anything.
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            if ($invitationId) {
                $this->store->recordHead($invitationId);
            }
            $this->plugin->answerWithoutEffect();
        }

        try {
            $validityDays = $this->plugin->getValidityDays($context->getId());
            $link = $invitationId ? $this->store->find($invitationId, $validityDays) : null;
            $review = $link ? Repo::reviewAssignment()->get((int) $link->review_assignment_id) : null;
            $decision = $this->decide($request, $context, $link, $key, $review);
        } catch (\Throwable $e) {
            error_log('ojsbrReviewerMagicLink: invitation ' . $invitationId . ': ' . $e->getMessage());
            $this->toLogin($request, $context, null, LinkDecision::REASON_INVALID);
        }

        if ($decision['action'] !== LinkDecision::ENTER) {
            if ($decision['reason'] === LinkDecision::REASON_OTHER_USER) {
                Validation::logout();
            }
            $this->toLogin($request, $context, $decision['back'] === LinkDecision::BACK_TO_REVIEW ? $review : null, $decision['reason']);
        }

        // Optional: a page with a button, so that a scanner following the link
        // with GET does not sign in on the reviewer's behalf.
        if ($request->getUser() === null && $this->plugin->getFlag($context->getId(), 'confirmPage')) {
            if (!$request->isPost()) {
                $this->showConfirmPage($request, $invitationId, $key);
            }
            if (!$request->checkCSRF()) {
                $this->toLogin($request, $context, $review, LinkDecision::REASON_INVALID);
            }
        }

        if ($request->getUser() === null) {
            $user = Repo::user()->get((int) $link->user_id);
            $reason = null;
            if (!$user || !Validation::registerUserSession($user, $reason)) {
                $this->toLogin($request, $context, $review, LinkDecision::REASON_INVALID);
            }
        }
        $this->store->recordUse($link, $request->getRemoteAddr(), $request->getUserAgent());

        $request->redirectUrl($this->reviewUrl($request, $context, $review));
    }

    /**
     * Gathers what the decision needs to know about the link, the review and
     * the person.
     */
    protected function decide(Request $request, Context $context, ?object $link, string $key, ?ReviewAssignment $review): array
    {
        $contextId = (int) $context->getId();
        $linkFound = $link !== null && (int) $link->context_id === $contextId;
        $user = $linkFound ? Repo::user()->get((int) $link->user_id, true) : null;
        $loggedIn = $request->getUser();

        return LinkDecision::decide([
            'linkFound' => $linkFound,
            'keyMatches' => $linkFound && $key !== '' && password_verify($key, (string) $link->key_hash),
            'expired' => $linkFound && Carbon::now()->greaterThan(Carbon::parse($link->expires_at)),
            'reviewFound' => $review !== null && (int) $review->getReviewerId() === (int) ($link->user_id ?? 0),
            'reviewClosed' => $review !== null && ($review->getDeclined() || $review->getCancelled() || $review->getDateCompleted()),
            'userFound' => $user !== null,
            'userDisabled' => $user !== null && (bool) $user->getDisabled(),
            'userRoleIds' => $user ? array_map(fn ($role) => (int) $role->getId(), $user->getRoles($contextId, true)) : [],
            'isSiteAdmin' => $user !== null && $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID),
            'allowedRoleIds' => $this->plugin->getAllowedRoleIds($contextId),
            'autoLogin' => $this->plugin->getFlag($contextId, 'autoLogin'),
            'loggedInUserId' => $loggedIn ? (int) $loggedIn->getId() : null,
            'linkUserId' => $linkFound ? (int) $link->user_id : null,
        ]);
    }

    /**
     * The review the link was sent for.
     */
    protected function reviewUrl(Request $request, Context $context, ?ReviewAssignment $review): string
    {
        return $review
            ? $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'reviewer', 'submission', null, ['submissionId' => $review->getSubmissionId(), 'reviewId' => $review->getId()])
            : $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'submissions');
    }

    /**
     * Sends the visitor to the login form of the journal, with the review (or
     * the reviewer's list) to return to and a short explanation.
     */
    protected function toLogin(Request $request, Context $context, ?ReviewAssignment $review, string $reason): never
    {
        $url = parse_url($this->reviewUrl($request, $context, $review));
        $source = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $message = $reason === LinkDecision::REASON_OTHER_USER
            ? 'plugins.generic.ojsbrReviewerMagicLink.login.otherUser'
            : 'plugins.generic.ojsbrReviewerMagicLink.login.expired';

        $request->redirect($context->getPath(), 'login', null, null, ['source' => $source, 'loginMessage' => $message]);
        exit;
    }

    /**
     * The page with the button that signs the reviewer in.
     */
    protected function showConfirmPage(Request $request, int $invitationId, string $key): never
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'invitationId' => $invitationId,
            'invitationKey' => $key,
            'pageTitle' => 'plugins.generic.ojsbrReviewerMagicLink.confirm.title',
        ]);
        $templateMgr->display($this->plugin->getTemplateResource('confirm.tpl'));
        exit;
    }
}
