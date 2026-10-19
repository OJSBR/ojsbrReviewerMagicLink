<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/classes/LinkStore.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LinkStore
 *
 * @brief The plugin's own copy of each link sent to a reviewer, and the record
 *        of the automatic entries.
 *
 * The core keeps only the hash of the key, and deletes the invitation of a
 * review as soon as a reminder creates a new one. A copy of the hash is taken
 * the moment the invitation is saved, so the link of every e-mail keeps
 * working for its own validity period.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\classes;

use APP\plugins\generic\ojsbrReviewerMagicLink\classes\migration\install\SchemaMigration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LinkStore
{
    public const LINKS = 'ojsbr_reviewer_links';
    public const USES = 'ojsbr_reviewer_link_uses';

    /** The core type of the reviewer invitations. */
    public const REVIEWER_TYPE = 'reviewerAccess';

    /** Links expired for longer than this are deleted. */
    public const PURGE_AFTER_DAYS = 30;

    /** Entries older than this are deleted from the record. */
    public const KEEP_USES_DAYS = 365;

    /** Whether the tables were already checked in this process. */
    private static bool $tablesChecked = false;

    public function __construct()
    {
        self::ensureTables();
    }

    /**
     * Creates the tables of the plugin when they are missing. The migration
     * only runs when the plugin is installed through the application; a plugin
     * folder copied by hand, or a database restored from a dump (PKP's
     * continuous integration), would otherwise have none.
     */
    public static function ensureTables(): void
    {
        if (self::$tablesChecked) {
            return;
        }
        self::$tablesChecked = true;
        if (!Schema::hasTable(self::LINKS) || !Schema::hasTable(self::USES)) {
            (new SchemaMigration())->up();
        }
    }

    /**
     * Keeps a copy of a reviewer link the first time its invitation is saved
     * with a key. Saving it again (the core updates the status) changes nothing.
     */
    public function capture(int $invitationId, string $keyHash, int $reviewAssignmentId, int $userId, int $contextId, int $validityDays, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        DB::table(self::LINKS)->insertOrIgnore([
            'invitation_id' => $invitationId,
            'key_hash' => $keyHash,
            'review_assignment_id' => $reviewAssignmentId,
            'user_id' => $userId,
            'context_id' => $contextId,
            'created_at' => $now->toDateTimeString(),
            'expires_at' => $now->copy()->addDays($validityDays)->toDateTimeString(),
        ]);
        $this->purge($now);
    }

    /**
     * The link of an invitation id: the plugin's copy, or, for a link sent
     * before the plugin was installed, the core invitation itself while it
     * still exists, in any status, with the validity counted from its creation.
     */
    public function find(int $invitationId, int $validityDays): ?object
    {
        $link = DB::table(self::LINKS)->where('invitation_id', $invitationId)->first();
        if ($link) {
            return $link;
        }

        $invitation = DB::table('invitations')
            ->where('invitation_id', $invitationId)
            ->where('type', self::REVIEWER_TYPE)
            ->whereNotNull('key_hash')
            ->first();
        if (!$invitation) {
            return null;
        }
        $reviewAssignmentId = (int) (json_decode((string) $invitation->payload, true)['reviewAssignmentId'] ?? 0);
        if (!$reviewAssignmentId || !$invitation->user_id || !$invitation->context_id) {
            return null;
        }

        $createdAt = Carbon::parse($invitation->created_at);
        $this->capture((int) $invitation->invitation_id, (string) $invitation->key_hash, $reviewAssignmentId, (int) $invitation->user_id, (int) $invitation->context_id, $validityDays, $createdAt);

        return DB::table(self::LINKS)->where('invitation_id', $invitationId)->first();
    }

    /**
     * Whether an invitation id belongs to another kind of invitation, which the
     * plugin must leave to the core.
     */
    public function isOtherInvitationType(int $invitationId): bool
    {
        if (DB::table(self::LINKS)->where('invitation_id', $invitationId)->exists()) {
            return false;
        }

        $type = DB::table('invitations')->where('invitation_id', $invitationId)->value('type');

        return $type !== null && $type !== self::REVIEWER_TYPE;
    }

    /**
     * Records an automatic entry: one row for the managers, and the counter of
     * the link.
     */
    public function recordUse(object $link, ?string $ip, ?string $userAgent, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        DB::table(self::USES)->insert([
            'invitation_id' => $link->invitation_id,
            'review_assignment_id' => $link->review_assignment_id,
            'user_id' => $link->user_id,
            'context_id' => $link->context_id,
            'used_at' => $now->toDateTimeString(),
            'ip' => $ip === null ? null : mb_substr($ip, 0, 64),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
        DB::table(self::LINKS)->where('invitation_id', $link->invitation_id)->update([
            'last_used_at' => $now->toDateTimeString(),
            'uses' => DB::raw('uses + 1'),
        ]);
    }

    /**
     * Counts a HEAD or OPTIONS request on a known link, without a row of its own.
     */
    public function recordHead(int $invitationId): void
    {
        DB::table(self::LINKS)->where('invitation_id', $invitationId)->update(['head_requests' => DB::raw('head_requests + 1')]);
    }

    /**
     * The latest automatic entries of a journal, newest first.
     */
    public function recentUses(int $contextId, int $limit = 20): array
    {
        return DB::table(self::USES . ' as u')
            ->leftJoin('users as us', 'us.user_id', '=', 'u.user_id')
            ->where('u.context_id', $contextId)
            ->orderByDesc('u.used_at')
            ->limit($limit)
            ->get(['u.used_at', 'u.review_assignment_id', 'u.invitation_id', 'u.ip', 'u.user_agent', 'us.username'])
            ->all();
    }

    /**
     * The HEAD and OPTIONS requests ignored on the links of a journal.
     */
    public function ignoredHeadRequests(int $contextId): int
    {
        return (int) DB::table(self::LINKS)->where('context_id', $contextId)->sum('head_requests');
    }

    /**
     * Deletes the links expired for more than 30 days and the old record.
     */
    public function purge(?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        DB::table(self::LINKS)->where('expires_at', '<', $now->copy()->subDays(self::PURGE_AFTER_DAYS)->toDateTimeString())->delete();
        DB::table(self::USES)->where('used_at', '<', $now->copy()->subDays(self::KEEP_USES_DAYS)->toDateTimeString())->delete();
    }
}
