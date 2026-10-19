<?php

/**
 * @file plugins/generic/ojsbrReviewerMagicLink/OjsbrReviewerMagicLinkSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrReviewerMagicLinkSettingsForm
 *
 * @brief How long the reviewer links of a journal last, who may enter with
 *        them, and the record of the entries.
 */

namespace APP\plugins\generic\ojsbrReviewerMagicLink;

use APP\plugins\generic\ojsbrReviewerMagicLink\classes\LinkStore;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class OjsbrReviewerMagicLinkSettingsForm extends Form
{
    public function __construct(public OjsbrReviewerMagicLinkPlugin $plugin, public Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorCustom(
            $this,
            'validityDays',
            'required',
            'plugins.generic.ojsbrReviewerMagicLink.settings.validityDays.invalid',
            fn ($days) => ctype_digit((string) $days) && (int) $days >= OjsbrReviewerMagicLinkPlugin::MIN_DAYS && (int) $days <= OjsbrReviewerMagicLinkPlugin::MAX_DAYS
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Load the settings of the journal.
     */
    public function initData(): void
    {
        $contextId = (int) $this->context->getId();
        $this->setData('validityDays', $this->plugin->getValidityDays($contextId));
        foreach (OjsbrReviewerMagicLinkPlugin::FLAGS as $name) {
            $this->setData($name, $this->plugin->getFlag($contextId, $name));
        }

        parent::initData();
    }

    /**
     * Read the settings sent by the form.
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_merge(['validityDays'], OjsbrReviewerMagicLinkPlugin::FLAGS));
        $this->setData('validityDays', trim((string) $this->getData('validityDays')));

        parent::readInputData();
    }

    /**
     * Show the form, with the latest entries and the requests ignored.
     *
     * @param \PKP\core\PKPRequest $request
     * @param null|string $template
     * @param bool $display
     */
    public function fetch($request, $template = null, $display = false)
    {
        $contextId = (int) $this->context->getId();
        $store = new LinkStore();
        TemplateManager::getManager($request)->assign([
            'pluginName' => $this->plugin->getName(),
            'accessKeysEnabled' => (bool) $this->context->getData('reviewerAccessKeysEnabled'),
            'recentUses' => $store->recentUses($contextId),
            'ignoredHeadRequests' => $store->ignoredHeadRequests($contextId),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the settings of the journal.
     */
    public function execute(...$functionArgs)
    {
        $contextId = (int) $this->context->getId();
        $this->plugin->updateSetting($contextId, 'validityDays', (int) $this->getData('validityDays'), 'int');
        foreach (OjsbrReviewerMagicLinkPlugin::FLAGS as $name) {
            $this->plugin->updateSetting($contextId, $name, $this->getData($name) ? 1 : 0, 'bool');
        }

        return parent::execute(...$functionArgs);
    }
}
