<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/classes/LinkDecision.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LinkDecision
 *
 * @brief What a click on a reviewer link leads to: straight into the review, or
 *        to the login form with the way back to the review.
 *
 * The decision is kept apart from the request so that every case can be tested
 * on its own. Nothing here ever leads to a 404: whatever is wrong with the link,
 * the visitor is sent to the login form, and the form never tells whether the
 * link existed.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\classes;

class LinkDecision
{
    public const ENTER = 'enter';
    public const LOGIN = 'login';

    /** After the login, back to the review. */
    public const BACK_TO_REVIEW = 'review';

    /** After the login, back to the reviewer's list: the review cannot be named. */
    public const BACK_TO_DASHBOARD = 'dashboard';

    public const REASON_INVALID = 'invalid';
    public const REASON_EXPIRED = 'expired';
    public const REASON_CLOSED = 'closed';
    public const REASON_NOT_ALLOWED = 'notAllowed';
    public const REASON_OTHER_USER = 'otherUser';
    public const REASON_AUTO_LOGIN_OFF = 'autoLoginOff';

    /**
     * @param array{
     *     linkFound: bool,
     *     keyMatches: bool,
     *     expired: bool,
     *     reviewFound: bool,
     *     reviewClosed: bool,
     *     userFound: bool,
     *     userDisabled: bool,
     *     userRoleIds: int[],
     *     isSiteAdmin: bool,
     *     allowedRoleIds: int[],
     *     autoLogin: bool,
     *     loggedInUserId: ?int,
     *     linkUserId: ?int,
     * } $facts
     *
     * @return array{action: string, reason: ?string, back: string}
     */
    public static function decide(array $facts): array
    {
        // A link that does not exist and a link with the wrong key look the same
        // from outside, so that nobody can find out which invitations exist.
        if (!$facts['linkFound'] || !$facts['keyMatches'] || !$facts['reviewFound']) {
            return self::login(self::REASON_INVALID, self::BACK_TO_DASHBOARD);
        }
        if (!$facts['userFound'] || $facts['userDisabled']) {
            return self::login(self::REASON_INVALID, self::BACK_TO_DASHBOARD);
        }
        if ($facts['expired']) {
            return self::login(self::REASON_EXPIRED, self::BACK_TO_REVIEW);
        }
        if ($facts['reviewClosed']) {
            return self::login(self::REASON_CLOSED, self::BACK_TO_REVIEW);
        }
        if ($facts['loggedInUserId'] !== null && $facts['loggedInUserId'] !== $facts['linkUserId']) {
            return self::login(self::REASON_OTHER_USER, self::BACK_TO_REVIEW);
        }
        if ($facts['loggedInUserId'] !== null) {
            // Already signed in as the reviewer: nothing to authenticate.
            return ['action' => self::ENTER, 'reason' => null, 'back' => self::BACK_TO_REVIEW];
        }
        if (!$facts['autoLogin']) {
            return self::login(self::REASON_AUTO_LOGIN_OFF, self::BACK_TO_REVIEW);
        }
        if (!self::onlyAllowedRoles($facts['userRoleIds'], $facts['allowedRoleIds'], $facts['isSiteAdmin'])) {
            return self::login(self::REASON_NOT_ALLOWED, self::BACK_TO_REVIEW);
        }

        return ['action' => self::ENTER, 'reason' => null, 'back' => self::BACK_TO_REVIEW];
    }

    /**
     * Whether the person holds only roles that may enter without a password:
     * a site administrator, or anyone holding a role outside the allowed list
     * in the journal (manager, editor, assistant...), never does.
     *
     * @param int[] $userRoleIds
     * @param int[] $allowedRoleIds
     */
    public static function onlyAllowedRoles(array $userRoleIds, array $allowedRoleIds, bool $isSiteAdmin): bool
    {
        if ($isSiteAdmin) {
            return false;
        }

        return array_diff(array_map('intval', $userRoleIds), array_map('intval', $allowedRoleIds)) === [];
    }

    /**
     * @return array{action: string, reason: string, back: string}
     */
    private static function login(string $reason, string $back): array
    {
        return ['action' => self::LOGIN, 'reason' => $reason, 'back' => $back];
    }
}
