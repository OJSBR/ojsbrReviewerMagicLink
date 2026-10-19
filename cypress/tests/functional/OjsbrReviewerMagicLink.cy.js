/**
 * @file cypress/tests/functional/OjsbrReviewerMagicLink.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings of the plugin, and what a request to
 * `invitation/accept` gets once the plugin answers it: a HEAD request has no
 * effect at all, and a link that cannot be used — wrong key, unknown id, no id —
 * leads to the login form with a short notice, never to a 404.
 *
 * Links with a real key only exist in the e-mails, so the clicks that sign a
 * reviewer in are covered by the unit tests and by the manual test plan of the
 * README.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration. The settings of the journal are put back as they were found.
 */

describe('Reviewer link for 30 days plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(). The Plugins tab can keep requests open for a
	// while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent, so that the session is not dropped.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('form#login').should('not.exist');
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	const withToken = (method, body) => cy.window({log: false}).then((win) => ({
		method,
		headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
		body: body ? JSON.stringify(body) : undefined,
	}));

	// A request whose error answer is kept, not thrown: yields {status, body}.
	const send = (path, method, body) => withToken(method, body).then((options) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => response.json().then((json) => ({status: response.status, body: json}))),
		{log: false, timeout: 30000}
	)));

	// The website settings page on its Plugins tab. Loaded once per run: loading
	// it again while its plugin gallery request is pending stalls the web server
	// of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page, and
	// waits for the form handler: after a failed validation the modal replaces
	// the form, and a click before that sends the form natively.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	const rowName = 'ojsbrreviewermagiclinkplugin';
	const settingsForm = 'form[id="ojsbrReviewerMagicLinkSettingsForm"]';
	const notice = 'plugins.generic.ojsbrReviewerMagicLink.login.expired';
	let settingsAction = null;
	let settingsWere = null;
	// The journal of the run and whether it sent the one-click link before.
	let journalId = null;
	let accessKeysWere = null;

	const journal = () => api(pageUrl('api/v1/contexts?count=100')).then((contexts) => {
		const found = (contexts.items || []).find((item) => item.urlPath === contextPath);
		expect(found, 'the journal ' + contextPath).to.not.eq(undefined);
		journalId = found.id;
		return api(pageUrl('api/v1/contexts/' + found.id));
	});

	const setAccessKeys = (value) => send(pageUrl('api/v1/contexts/' + journalId), 'PUT', {reviewerAccessKeysEnabled: value}).then((answer) => {
		expect(answer.status, JSON.stringify(answer.body)).to.eq(200);
		expect(answer.body.reviewerAccessKeysEnabled).to.eq(value);
	});

	const settingsOf = ($form) => ({
		validityDays: $form.find('input[name="validityDays"]').val(),
		autoLogin: $form.find('input[name="autoLogin"]').is(':checked'),
		allowAuthor: $form.find('input[name="allowAuthor"]').is(':checked'),
		allowReader: $form.find('input[name="allowReader"]').is(':checked'),
		confirmPage: $form.find('input[name="confirmPage"]').is(':checked'),
	});

	// Saves the settings from whatever page is open, exactly as the modal does.
	const saveSettings = (values) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
			body: ['autoLogin', 'allowAuthor', 'allowReader', 'confirmPage'].filter((name) => values[name]).map((name) => name + '=on')
				.concat('validityDays=' + encodeURIComponent(values.validityDays))
				.concat('csrfToken=' + encodeURIComponent(win.pkp.currentUser.csrfToken))
				.join('&'),
		}).then((response) => response.json()),
		{log: false, timeout: 30000}
	));

	const fetchSettings = () => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(settingsAction.replace(/([?&])save=[^&]*&?/, '$1').replace(/[?&]$/, ''), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then((response) => response.json())
			.then((json) => Cypress.$('<div>').html(json.content || '')),
		{log: false, timeout: 30000}
	));

	// The links of the e-mails carry the language (/en/invitation/accept); a URL
	// without it is first redirected by the core to the one with it.
	let language = null;
	const acceptUrl = (query) => '/index.php/' + contextPath + '/' + language + '/invitation/accept' + (query ? '?' + query : '');
	const findLanguage = () => cy.request({url: pageUrl('invitation/accept'), followRedirect: false, failOnStatusCode: false}).then((response) => {
		const found = /\/index\.php\/[^/]+\/([a-z]{2}(?:_[A-Za-z]+)?)\//.exec(response.headers.location || '');
		language = found ? found[1] : 'en';
	});

	it('Enables the plugin and offers a settings form for the journal', function() {
		login(adminUser, adminPassword);
		// The plugin only acts where the journal sends the one-click link.
		journal().then((context) => {
			accessKeysWere = !!context.reviewerAccessKeysEnabled;
			setAccessKeys(true);
		});
		openPluginsTab();
		enablePlugin(rowName);
		openPluginSettings(rowName, settingsForm);

		cy.get(settingsForm + ' input[name="validityDays"]').should('have.length', 1);
		['autoLogin', 'allowAuthor', 'allowReader', 'confirmPage'].forEach((name) => cy.get(settingsForm + ' input[name="' + name + '"]').should('have.length', 1));
		cy.get(settingsForm).invoke('text').should('not.contain', '##');
		cy.get(settingsForm).invoke('attr', 'action').then((action) => {
			settingsAction = action;
		});
		cy.get(settingsForm).then(($form) => {
			settingsWere = settingsOf($form);
		});
	});

	it('Keeps the validity and the switches, and refuses a validity out of range', function() {
		login(adminUser, adminPassword);
		cy.then(() => saveSettings({validityDays: 45, autoLogin: true, allowAuthor: false, allowReader: true, confirmPage: true}));
		fetchSettings().then(($form) => {
			expect(settingsOf($form)).to.deep.eq({validityDays: '45', autoLogin: true, allowAuthor: false, allowReader: true, confirmPage: true});
		});
		cy.then(() => saveSettings({validityDays: 400, autoLogin: true})).then((json) => {
			expect(json.content || '', 'the form comes back').to.not.eq('');
		});
		fetchSettings().then(($form) => {
			expect(settingsOf($form).validityDays, 'nothing was stored').to.eq('45');
		});
	});

	it('Answers a HEAD request with nothing at all', function() {
		cy.clearCookies();
		findLanguage();
		cy.then(() => cy.request({method: 'HEAD', url: acceptUrl('id=1&key=anything'), followRedirect: false, failOnStatusCode: false})).then((response) => {
			expect(response.status).to.eq(200);
			expect(response.headers).to.not.have.property('set-cookie');
		});
	});

	it('Leads a link that cannot be used to the login form, never to a 404', function() {
		cy.clearCookies();
		findLanguage();
		['id=987654321&key=wrong', 'id=1&key=wrong', ''].forEach((query) => {
			cy.then(() => cy.request({url: acceptUrl(query), followRedirect: false, failOnStatusCode: false})).then((response) => {
				expect(response.status, query || 'no id').to.be.oneOf([301, 302, 303]);
				expect(response.headers.location).to.contain('/login');
				expect(decodeURIComponent(response.headers.location)).to.contain('loginMessage=' + notice);
				expect(decodeURIComponent(response.headers.location)).to.contain('source=/');
			});
		});
		cy.then(() => cy.visit(acceptUrl('id=987654321&key=wrong')));
		cy.get('form#login').should('exist');
		cy.get('body').invoke('text').should('not.contain', '##');
	});

	after(function() {
		login(adminUser, adminPassword);
		cy.then(() => (settingsWere && settingsAction ? saveSettings(settingsWere) : null));
		cy.then(() => (accessKeysWere === null ? null : setAccessKeys(accessKeysWere)));
	});
});
