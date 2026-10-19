{**
 * plugins/generic/ojsbrReviewerMagicLink/templates/confirm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The page with the button that signs the reviewer in, shown when the journal
 * asks for it: a mail scanner following the link does not press buttons.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.ojsbrReviewerMagicLink.confirm.title"}

<div class="page page_ojsbr_reviewer_link">
	<h1>{translate key="plugins.generic.ojsbrReviewerMagicLink.confirm.title"}</h1>
	<p>{translate key="plugins.generic.ojsbrReviewerMagicLink.confirm.text"}</p>
	<form method="post" action="{url page="invitation" op="accept" id=$invitationId key=$invitationKey}">
		{csrf}
		<button class="pkp_button" type="submit">{translate key="plugins.generic.ojsbrReviewerMagicLink.confirm.button"}</button>
	</form>
</div>

{include file="frontend/components/footer.tpl"}
