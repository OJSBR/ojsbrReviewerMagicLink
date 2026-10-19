<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/OjsbrReviewerMagicLinkPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrReviewerMagicLinkPlugin
 *
 * @brief Keeps the one-click link sent to reviewers working for a fixed number
 *        of days (30 by default), however many times it is opened, and leads to
 *        the login form instead of a 404 once it no longer can.
 *
 * Three things in OJS 3.5 stop reviewers from getting in with the link of their
 * e-mail:
 *
 * - up to 3.5.0-4 the first request to the link uses it up, and mail scanners
 *   (Microsoft Safe Links sends a HEAD request a second before the click) get
 *   there first (fixed by the core in 3.5.0-5, pkp/pkp-lib#12608);
 * - every reminder deletes the invitation of the previous e-mail, whose link
 *   then answers 404 (a design decision of the core);
 * - an expired or deleted link answers 404 or "invitation not available"
 *   instead of offering the login form.
 *
 * Two extension points, both documented: a listener on the saving of the core
 * invitation model keeps a copy of the hash of each reviewer link, and the
 * `LoadHandler` hook answers `invitation/accept` for reviewer links. Every
 * other kind of invitation is left to the core. Both are registered whatever
 * the context of the request — reminders are sent by the scheduled tasks, from
 * the command line — and each checks whether the plugin is enabled in the
 * journal of the invitation (pkp/pkp-lib#11793).
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink;

use APP\core\Application;
use APP\notification\NotificationManager;
use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkStore;
use APP\plugins\generic\ojsbrReviewerMagicLink\classes\migration\install\SchemaMigration;
use APP\plugins\generic\ojsbrReviewerMagicLink\pages\ReviewerLinkHandler;
use PKP\core\JSONMessage;
use PKP\invitation\models\InvitationModel;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class OjsbrReviewerMagicLinkPlugin extends GenericPlugin
{
    /** The settings of a journal and what they are while nothing was saved. */
    public const DEFAULTS = [
        'validityDays' => 30,
        'autoLogin' => true,
        'allowAuthor' => true,
        'allowReader' => true,
        'confirmPage' => false,
    ];

    public const FLAGS = ['autoLogin', 'allowAuthor', 'allowReader', 'confirmPage'];

    public const MIN_DAYS = 1;
    public const MAX_DAYS = 365;

    /** Whether the invitation listener is already attached in this process. */
    private static bool $listening = false;

    /**
     * Register the plugin and its two extension points.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        Hook::add('LoadHandler', $this->loadHandler(...));
        if (!self::$listening) {
            self::$listening = true;
            InvitationModel::saved(fn (InvitationModel $invitation) => $this->captureLink($invitation));
        }

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.ojsbrReviewerMagicLink.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.ojsbrReviewerMagicLink.description');
    }

    /**
     * The tables of the links and of the entries.
     */
    public function getInstallMigration(): SchemaMigration
    {
        return new SchemaMigration();
    }

    /**
     * Whether the plugin acts in a journal: enabled there, and the journal sends
     * the one-click link to its reviewers.
     */
    public function isActiveIn(?int $contextId): bool
    {
        if (!$contextId || !$this->getEnabled($contextId)) {
            return false;
        }
        $context = Application::getContextDAO()->getById($contextId);

        return (bool) $context?->getData('reviewerAccessKeysEnabled');
    }

    /**
     * Listener of the saving of a core invitation: keeps a copy of the hash of
     * every reviewer link, the first time it is saved with a key.
     */
    public function captureLink(InvitationModel $invitation): void
    {
        try {
            if ($invitation->type !== LinkStore::REVIEWER_TYPE || empty($invitation->keyHash) || !$this->isActiveIn((int) $invitation->contextId)) {
                return;
            }
            $reviewAssignmentId = (int) ($invitation->payload['reviewAssignmentId'] ?? 0);
            if (!$reviewAssignmentId || !$invitation->userId) {
                return;
            }
            (new LinkStore())->capture(
                (int) $invitation->id,
                (string) $invitation->keyHash,
                $reviewAssignmentId,
                (int) $invitation->userId,
                (int) $invitation->contextId,
                $this->getValidityDays((int) $invitation->contextId)
            );
        } catch (\Throwable $e) {
            // Sending the e-mail must never fail because of the copy.
            error_log('ojsbrReviewerMagicLink: the link of invitation ' . ($invitation->id ?? '?') . ' was not kept: ' . $e->getMessage());
        }
    }

    /**
     * Hook LoadHandler — answers `invitation/accept` for the reviewer links of
     * a journal where the plugin acts; every other invitation goes to the core.
     *
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function loadHandler(string $hookName, array $args): bool
    {
        $page = $args[0];
        $op = $args[1];
        if ($page !== 'invitation' || $op !== 'accept') {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->isActiveIn((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        $store = new LinkStore();
        $invitationId = (int) $request->getUserVar('id');
        if ($invitationId && $store->isOtherInvitationType($invitationId)) {
            return Hook::CONTINUE;
        }

        $handler = & $args[3];
        $handler = new ReviewerLinkHandler($this, $store);

        return Hook::ABORT;
    }

    /**
     * Answers a HEAD or OPTIONS request with a bare 200 and stops: no session
     * is touched and nothing is changed.
     */
    public function answerWithoutEffect(): never
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit;
    }

    /**
     * The validity of a link, in days.
     */
    public function getValidityDays(?int $contextId): int
    {
        $days = $contextId ? (int) $this->getSetting($contextId, 'validityDays') : 0;

        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS ? $days : self::DEFAULTS['validityDays'];
    }

    /**
     * A switch of the journal, or its default while nothing was saved.
     */
    public function getFlag(?int $contextId, string $name): bool
    {
        if (!in_array($name, self::FLAGS, true)) {
            return false;
        }
        $value = $contextId ? $this->getSetting($contextId, $name) : null;

        return $value === null || $value === '' ? self::DEFAULTS[$name] : (bool) $value;
    }

    /**
     * The roles a person may hold in the journal and still enter with the link:
     * reviewer always, author and reader if the journal allows them.
     *
     * @return int[]
     */
    public function getAllowedRoleIds(?int $contextId): array
    {
        $roles = [Role::ROLE_ID_REVIEWER];
        if ($this->getFlag($contextId, 'allowAuthor')) {
            $roles[] = Role::ROLE_ID_AUTHOR;
        }
        if ($this->getFlag($contextId, 'allowReader')) {
            $roles[] = Role::ROLE_ID_READER;
        }

        return $roles;
    }

    /**
     * The settings action, while the plugin is enabled in a journal.
     *
     * @param \PKP\core\PKPRequest $request
     * @param array $actionArgs
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal($router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']), $this->getDisplayName()),
            __('manager.plugins.settings'),
            null
        ));

        return $actions;
    }

    /**
     * Shows and saves the settings of the journal. There is nothing to set
     * without a journal.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings' || !$request->getContext()) {
            return parent::manage($args, $request);
        }

        $form = new OjsbrReviewerMagicLinkSettingsForm($this, $request->getContext());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsbrReviewerMagicLink\OjsbrReviewerMagicLinkPlugin', '\OjsbrReviewerMagicLinkPlugin');
}
