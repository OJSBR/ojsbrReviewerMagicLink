<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/tests/LinkDecisionTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LinkDecisionTest
 *
 * @brief Every case of a click on a reviewer link: straight into the review, or
 *        to the login form — never a 404 — and where the login leads back to.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\tests;

use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\security\Role;
use PKP\tests\PKPTestCase;

#[CoversClass(LinkDecision::class)]
class LinkDecisionTest extends PKPTestCase
{
    /** A valid link of a reviewer who is only a reviewer, nobody signed in. */
    protected function facts(array $changes = []): array
    {
        return array_merge([
            'linkFound' => true,
            'keyMatches' => true,
            'expired' => false,
            'reviewFound' => true,
            'reviewClosed' => false,
            'userFound' => true,
            'userDisabled' => false,
            'userRoleIds' => [Role::ROLE_ID_REVIEWER],
            'isSiteAdmin' => false,
            'allowedRoleIds' => [Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_READER],
            'autoLogin' => true,
            'loggedInUserId' => null,
            'linkUserId' => 3,
        ], $changes);
    }

    protected function assertLogin(array $decision, string $reason, string $back): void
    {
        $this->assertSame(['action' => LinkDecision::LOGIN, 'reason' => $reason, 'back' => $back], $decision);
    }

    public function testAValidLinkOfAReviewerEntersTheReview(): void
    {
        $this->assertSame(LinkDecision::ENTER, LinkDecision::decide($this->facts())['action']);
        // Also an author and a reader of the same journal.
        $this->assertSame(LinkDecision::ENTER, LinkDecision::decide($this->facts(['userRoleIds' => [Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_READER]]))['action']);
    }

    public function testAnUnknownLinkOrAWrongKeyLookTheSameAndLeadToTheReviewerList(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['linkFound' => false])), LinkDecision::REASON_INVALID, LinkDecision::BACK_TO_DASHBOARD);
        $this->assertLogin(LinkDecision::decide($this->facts(['keyMatches' => false])), LinkDecision::REASON_INVALID, LinkDecision::BACK_TO_DASHBOARD);
        $this->assertLogin(LinkDecision::decide($this->facts(['reviewFound' => false])), LinkDecision::REASON_INVALID, LinkDecision::BACK_TO_DASHBOARD);
    }

    public function testAnExpiredLinkLeadsToTheLoginAndBackToTheReview(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['expired' => true])), LinkDecision::REASON_EXPIRED, LinkDecision::BACK_TO_REVIEW);
    }

    public function testAClosedReviewLeadsToTheLogin(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['reviewClosed' => true])), LinkDecision::REASON_CLOSED, LinkDecision::BACK_TO_REVIEW);
    }

    public function testADisabledOrMissingUserNeverEnters(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['userDisabled' => true])), LinkDecision::REASON_INVALID, LinkDecision::BACK_TO_DASHBOARD);
        $this->assertLogin(LinkDecision::decide($this->facts(['userFound' => false])), LinkDecision::REASON_INVALID, LinkDecision::BACK_TO_DASHBOARD);
    }

    public function testWhoeverRunsTheJournalAlwaysGetsTheLogin(): void
    {
        foreach ([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT] as $role) {
            $this->assertLogin(
                LinkDecision::decide($this->facts(['userRoleIds' => [Role::ROLE_ID_REVIEWER, $role]])),
                LinkDecision::REASON_NOT_ALLOWED,
                LinkDecision::BACK_TO_REVIEW
            );
        }
        $this->assertLogin(LinkDecision::decide($this->facts(['isSiteAdmin' => true])), LinkDecision::REASON_NOT_ALLOWED, LinkDecision::BACK_TO_REVIEW);
        // An author when the journal allows reviewers only.
        $this->assertLogin(
            LinkDecision::decide($this->facts(['userRoleIds' => [Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR], 'allowedRoleIds' => [Role::ROLE_ID_REVIEWER]])),
            LinkDecision::REASON_NOT_ALLOWED,
            LinkDecision::BACK_TO_REVIEW
        );
    }

    public function testTheJournalCanSwitchTheAutomaticEntryOff(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['autoLogin' => false])), LinkDecision::REASON_AUTO_LOGIN_OFF, LinkDecision::BACK_TO_REVIEW);
    }

    public function testSomebodyElseSignedInIsAskedToSignInAsTheReviewer(): void
    {
        $this->assertLogin(LinkDecision::decide($this->facts(['loggedInUserId' => 99])), LinkDecision::REASON_OTHER_USER, LinkDecision::BACK_TO_REVIEW);
        // The reviewer already signed in goes straight to the review, whatever the roles.
        $this->assertSame(LinkDecision::ENTER, LinkDecision::decide($this->facts(['loggedInUserId' => 3, 'userRoleIds' => [Role::ROLE_ID_MANAGER]]))['action']);
    }

    public function testNoCaseEverEndsAnywhereButTheReviewOrTheLogin(): void
    {
        $flags = ['linkFound', 'keyMatches', 'expired', 'reviewFound', 'reviewClosed', 'userFound', 'userDisabled', 'isSiteAdmin', 'autoLogin'];
        for ($mask = 0; $mask < (1 << count($flags)); $mask++) {
            $changes = [];
            foreach ($flags as $i => $flag) {
                $changes[$flag] = (bool) ($mask & (1 << $i));
            }
            $decision = LinkDecision::decide($this->facts($changes));
            $this->assertContains($decision['action'], [LinkDecision::ENTER, LinkDecision::LOGIN]);
            if ($decision['action'] === LinkDecision::ENTER) {
                $this->assertTrue($changes['linkFound'] && $changes['keyMatches'] && $changes['reviewFound'] && $changes['userFound'] && !$changes['userDisabled'] && !$changes['expired'] && !$changes['reviewClosed'] && !$changes['isSiteAdmin'] && $changes['autoLogin']);
            }
        }
    }
}
