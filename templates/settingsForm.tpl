{**
 * plugins/generic/ojsbrReviewerMagicLink/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * How long the reviewer links of a journal last, who may enter with them, and
 * the record of the entries.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#ojsbrReviewerMagicLinkSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="ojsbrReviewerMagicLinkSettingsForm"
	method="POST"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="ojsbrReviewerMagicLinkSettingsNotification"}

	<p class="pkp_help">{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.intro"}</p>
	{if !$accessKeysEnabled}
		<p class="pkp_help"><strong>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.accessKeysDisabled"}</strong></p>
	{/if}

	{fbvFormArea id="ojsbrReviewerMagicLinkValidity" title="plugins.generic.ojsbrReviewerMagicLink.settings.area.validity"}
		{fbvFormSection description="plugins.generic.ojsbrReviewerMagicLink.settings.validityDays.description"}
			{fbvElement type="text" id="validityDays" name="validityDays" value=$validityDays maxlength="3" size=$fbvStyles.size.SMALL label="plugins.generic.ojsbrReviewerMagicLink.settings.validityDays"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="ojsbrReviewerMagicLinkAccess" title="plugins.generic.ojsbrReviewerMagicLink.settings.area.access" description="plugins.generic.ojsbrReviewerMagicLink.settings.area.access.description"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="autoLogin" name="autoLogin" checked=$autoLogin label="plugins.generic.ojsbrReviewerMagicLink.settings.autoLogin"}
			{fbvElement type="checkbox" id="allowAuthor" name="allowAuthor" checked=$allowAuthor label="plugins.generic.ojsbrReviewerMagicLink.settings.allowAuthor"}
			{fbvElement type="checkbox" id="allowReader" name="allowReader" checked=$allowReader label="plugins.generic.ojsbrReviewerMagicLink.settings.allowReader"}
			{fbvElement type="checkbox" id="confirmPage" name="confirmPage" checked=$confirmPage label="plugins.generic.ojsbrReviewerMagicLink.settings.confirmPage"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="ojsbrReviewerMagicLinkUses" title="plugins.generic.ojsbrReviewerMagicLink.settings.area.uses"}
		<p class="pkp_help">{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.ignoredHeadRequests" requests=$ignoredHeadRequests|escape}</p>
		{if $recentUses}
			<table class="pkpTable" id="ojsbrReviewerMagicLinkUsesTable">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.date"}</th>
						<th>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.user"}</th>
						<th>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.review"}</th>
						<th>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.ip"}</th>
						<th>{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.userAgent"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$recentUses item=use}
						<tr>
							<td>{$use->used_at|escape}</td>
							<td>{$use->username|escape}</td>
							<td>{$use->review_assignment_id|escape}</td>
							<td>{$use->ip|escape}</td>
							<td>{$use->user_agent|truncate:60|escape}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<p class="pkp_help">{translate key="plugins.generic.ojsbrReviewerMagicLink.settings.uses.none"}</p>
		{/if}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
