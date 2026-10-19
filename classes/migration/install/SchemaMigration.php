<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/classes/migration/install/SchemaMigration.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SchemaMigration
 *
 * @brief The links sent to reviewers, kept apart from the core invitations,
 *        and the record of every automatic entry.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink\classes\migration\install;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaMigration extends Migration
{
    public function up(): void
    {
        // One row per link sent. There is deliberately no foreign key to
        // `invitations`: the core deletes the previous invitation of a review
        // every time a reminder is sent, and the link of the earlier e-mail has
        // to keep working. Only the hash of the key is kept, as in the core.
        if (!Schema::hasTable('ojsbr_reviewer_links')) {
            Schema::create('ojsbr_reviewer_links', function (Blueprint $table) {
                $table->bigInteger('invitation_id');
                $table->string('key_hash', 255);
                $table->bigInteger('review_assignment_id');
                $table->bigInteger('user_id');
                $table->bigInteger('context_id');
                $table->datetime('created_at');
                $table->datetime('expires_at');
                $table->datetime('last_used_at')->nullable();
                $table->integer('uses')->default(0);
                $table->integer('head_requests')->default(0);

                $table->primary(['invitation_id'], 'ojsbr_reviewer_links_pkey');
                $table->index(['expires_at'], 'ojsbr_reviewer_links_expires_at');
                $table->index(['context_id'], 'ojsbr_reviewer_links_context_id');
            });
        }

        // One row per automatic entry, for the journal managers.
        if (!Schema::hasTable('ojsbr_reviewer_link_uses')) {
            Schema::create('ojsbr_reviewer_link_uses', function (Blueprint $table) {
                $table->bigIncrements('use_id');
                $table->bigInteger('invitation_id');
                $table->bigInteger('review_assignment_id');
                $table->bigInteger('user_id');
                $table->bigInteger('context_id');
                $table->datetime('used_at');
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 255)->nullable();

                $table->index(['context_id', 'used_at'], 'ojsbr_reviewer_link_uses_context');
                $table->index(['used_at'], 'ojsbr_reviewer_link_uses_used_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ojsbr_reviewer_link_uses');
        Schema::dropIfExists('ojsbr_reviewer_links');
    }
}
