<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/tests/LinkStoreTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LinkStoreTest
 *
 * @brief The plugin's copy of the links, against the database of the
 *        installation: the copy outlives the core invitation, a link sent
 *        before the plugin is still honoured, other invitations are left alone,
 *        and old rows are cleaned up. Everything runs inside a transaction that
 *        is rolled back.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\tests;

use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkStore;
use APP\plugins\generic\ojsbrReviewerMagicLink\classes\migration\install\SchemaMigration;
use APP\plugins\generic\ojsbrReviewerMagicLink\OjsbrReviewerMagicLinkPlugin;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\invitation\models\InvitationModel;
use PKP\tests\PKPTestCase;

#[CoversClass(LinkStore::class)]
#[CoversClass(OjsbrReviewerMagicLinkPlugin::class)]
class LinkStoreTest extends PKPTestCase
{
    /** Whether this test created the tables (and must drop them). */
    private static bool $createdTables = false;

    private int $userId;
    private int $contextId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // A plugin folder copied by hand never runs its migration; in PKP's
        // continuous integration the tables do not exist either.
        if (!Schema::hasTable(LinkStore::LINKS)) {
            (new SchemaMigration())->up();
            self::$createdTables = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdTables) {
            (new SchemaMigration())->down();
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int) DB::table('users')->min('user_id');
        $this->contextId = (int) DB::table('journals')->min('journal_id');
        if (!$this->userId || !$this->contextId) {
            $this->markTestSkipped('Needs a user and a journal.');
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** A core invitation row, as the core leaves it in the database. */
    protected function invitation(string $type, string $key, string $status = 'PENDING', ?string $createdAt = null): int
    {
        return (int) DB::table('invitations')->insertGetId([
            'key_hash' => password_hash($key, PASSWORD_BCRYPT),
            'type' => $type,
            'user_id' => $this->userId,
            'payload' => json_encode(['reviewAssignmentId' => 777001]),
            'status' => $status,
            'context_id' => $this->contextId,
            'created_at' => $createdAt ?? Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ], 'invitation_id');
    }

    public function testACopyIsKeptOnceAndOutlivesTheCoreInvitation(): void
    {
        $store = new LinkStore();
        $now = Carbon::parse('2026-10-01 10:00:00');
        $store->capture(900001, password_hash('secret', PASSWORD_BCRYPT), 777001, $this->userId, $this->contextId, 30, $now);
        // The core saves the invitation again (status change): nothing changes.
        $store->capture(900001, 'another hash', 777001, $this->userId, $this->contextId, 5, $now->copy()->addDay());

        $link = DB::table(LinkStore::LINKS)->where('invitation_id', 900001)->first();
        $this->assertTrue(password_verify('secret', $link->key_hash));
        $this->assertSame('2026-10-31 10:00:00', Carbon::parse($link->expires_at)->toDateTimeString());
        // There is no core invitation 900001 at all, and the link is still found.
        $this->assertSame(900001, (int) $store->find(900001, 30)->invitation_id);
    }

    public function testALinkSentBeforeThePluginIsHonouredInAnyStatusFromItsCreation(): void
    {
        $id = $this->invitation(LinkStore::REVIEWER_TYPE, 'k', 'ACCEPTED', '2026-10-01 08:00:00');
        $store = new LinkStore();

        $link = $store->find($id, 30);
        $this->assertNotNull($link);
        $this->assertTrue(password_verify('k', $link->key_hash));
        $this->assertSame(777001, (int) $link->review_assignment_id);
        $this->assertSame('2026-10-31 08:00:00', Carbon::parse($link->expires_at)->toDateTimeString());

        // A reminder deletes the core invitation: the copy is what answers now.
        DB::table('invitations')->where('invitation_id', $id)->delete();
        $this->assertSame($id, (int) $store->find($id, 30)->invitation_id);
    }

    public function testOtherInvitationsAreLeftToTheCore(): void
    {
        $store = new LinkStore();
        $other = $this->invitation('userRoleAssignment', 'k');
        $reviewer = $this->invitation(LinkStore::REVIEWER_TYPE, 'k');

        $this->assertTrue($store->isOtherInvitationType($other));
        $this->assertNull($store->find($other, 30));
        $this->assertFalse($store->isOtherInvitationType($reviewer));
        // A link that matches nothing is the plugin's: it leads to the login form.
        $this->assertFalse($store->isOtherInvitationType(987654321));
    }

    public function testEntriesAndIgnoredRequestsAreCounted(): void
    {
        $store = new LinkStore();
        $store->capture(900002, 'h', 777001, $this->userId, $this->contextId, 30);
        $store->recordHead(900002);
        $store->recordHead(900002);
        $link = $store->find(900002, 30);
        $store->recordUse($link, '203.0.113.7', str_repeat('A', 400));

        $link = $store->find(900002, 30);
        $this->assertSame(2, (int) $link->head_requests);
        $this->assertSame(1, (int) $link->uses);
        $this->assertNotNull($link->last_used_at);
        $use = DB::table(LinkStore::USES)->where('invitation_id', 900002)->first();
        $this->assertSame('203.0.113.7', $use->ip);
        $this->assertSame(255, strlen($use->user_agent));
        $this->assertSame(2, $store->ignoredHeadRequests($this->contextId) - $this->headsOutsideThisTest(900002));
    }

    public function testLinksExpiredForMoreThanThirtyDaysAreDeleted(): void
    {
        $store = new LinkStore();
        $now = Carbon::parse('2026-10-19 12:00:00');
        $store->capture(900003, 'h', 777001, $this->userId, $this->contextId, 30, $now->copy()->subDays(71));
        $store->capture(900004, 'h', 777001, $this->userId, $this->contextId, 30, $now->copy()->subDays(50));
        $store->purge($now);

        $this->assertFalse(DB::table(LinkStore::LINKS)->where('invitation_id', 900003)->exists(), 'expired 41 days ago');
        $this->assertTrue(DB::table(LinkStore::LINKS)->where('invitation_id', 900004)->exists(), 'expired 20 days ago');
    }

    public function testTheCoreInvitationIsCopiedWhenSavedWithAKey(): void
    {
        $plugin = new class () extends OjsbrReviewerMagicLinkPlugin {
            public function isActiveIn(?int $contextId): bool
            {
                return true;
            }

            public function getValidityDays(?int $contextId): int
            {
                return 30;
            }
        };
        $id = $this->invitation(LinkStore::REVIEWER_TYPE, 'clicked key');
        $other = $this->invitation('userRoleAssignment', 'k');

        $plugin->captureLink(InvitationModel::find($id));
        $plugin->captureLink(InvitationModel::find($other));

        $link = DB::table(LinkStore::LINKS)->where('invitation_id', $id)->first();
        $this->assertNotNull($link);
        $this->assertTrue(password_verify('clicked key', $link->key_hash));
        $this->assertSame(777001, (int) $link->review_assignment_id);
        $this->assertFalse(DB::table(LinkStore::LINKS)->where('invitation_id', $other)->exists());
    }

    /** HEAD requests already counted in the journal by real links, if any. */
    private function headsOutsideThisTest(int $invitationId): int
    {
        return (int) DB::table(LinkStore::LINKS)->where('context_id', $this->contextId)->where('invitation_id', '<>', $invitationId)->sum('head_requests');
    }
}
