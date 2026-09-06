/*
 * shared.js -- the pieces both pages need.
 *
 * The module is two pages: views/list.php (what exists) and views/edit.php
 * (make one). They talk to the same ajax endpoint, spell a recording name by
 * the same rule, and link to each other, so that lot lives here rather than in
 * one page's script with the other reaching across for it.
 *
 * Loaded first on both pages. Everything else assumes it is already there.
 */
(function (window, document) {
	'use strict';

	var AJAX = 'ajax.php';
	var MODULE = 'oryk_media';
	var DISPLAY = 'oryk_media';

	/**
	 * What a recording may be called. Kept in step with
	 * Oryk_media::NAME_PATTERN, which is the one that actually decides.
	 */
	var NAME_RE = /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/;

	/* ------------------------------------------------------------------ */
	/* Formatting                                                          */
	/* ------------------------------------------------------------------ */

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	/** hh:mm:ss:cc -- the recorder's clock, also used to label a finished take. */
	function clock(seconds) {
		var cs = Math.floor((seconds || 0) * 100);
		var h = Math.floor(cs / 360000);
		var m = Math.floor((cs % 360000) / 6000);
		var s = Math.floor((cs % 6000) / 100);

		return pad2(h) + ':' + pad2(m) + ':' + pad2(s) + ':' + pad2(cs % 100);
	}

	function bytes(n) {
		if (!n) {
			return '0 B';
		}
		if (n < 1024) {
			return n + ' B';
		}
		if (n < 1048576) {
			return (n / 1024).toFixed(1) + ' KB';
		}

		return (n / 1048576).toFixed(1) + ' MB';
	}

	function when(unixSeconds) {
		if (!unixSeconds) {
			return '';
		}

		return new Date(unixSeconds * 1000).toLocaleString();
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function toast(message, kind) {
		if (typeof window.fpbxToast === 'function') {
			window.fpbxToast(message, kind === 'error' ? 'Error' : '', kind || 'success');
		}
	}

	/* ------------------------------------------------------------------ */
	/* Where things are                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * A URL for this module's page. The query string is rebuilt rather than
	 * amended: `display` is the only parameter that survives a move between the
	 * two pages, and carrying a stale `edit` or `saved` across is exactly the
	 * bug this avoids.
	 */
	function pageUrl(params) {
		var query = 'display=' + encodeURIComponent(DISPLAY);

		Object.keys(params || {}).forEach(function (key) {
			query += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
		});

		return window.location.pathname + '?' + query;
	}

	function listUrl(saved) {
		return pageUrl(saved ? { saved: saved } : {});
	}

	/** The editor. No name means a new recording -- `edit` is present but empty. */
	function editUrl(name) {
		return pageUrl({ edit: name || '' });
	}

	/** Streams a saved recording. `bust` defeats the cache after an overwrite. */
	function playUrl(name, bust) {
		return AJAX + '?module=' + MODULE + '&command=play&name=' + encodeURIComponent(name) +
			(bust ? '&t=' + Date.now() : '');
	}

	function go(url) {
		window.location.href = url;
	}

	/* ------------------------------------------------------------------ */
	/* Transport                                                           */
	/* ------------------------------------------------------------------ */

	function post(data) {
		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();

			xhr.open('POST', AJAX, true);
			xhr.responseType = 'json';

			xhr.onload = function () {
				var body = xhr.response;

				if (typeof body === 'string') {
					try {
						body = JSON.parse(body);
					} catch (e) {
						body = null;
					}
				}

				if (!body) {
					reject(new Error('The server sent back something unreadable.'));
					return;
				}

				resolve(body);
			};

			xhr.onerror = function () {
				reject(new Error('Could not reach the server.'));
			};

			xhr.send(data);
		});
	}

	/** A form body with the module and command already filled in. */
	function command(name, fields) {
		var form = new FormData();

		form.append('module', MODULE);
		form.append('command', name);

		Object.keys(fields || {}).forEach(function (key) {
			form.append(key, fields[key]);
		});

		return form;
	}

	/* ------------------------------------------------------------------ */
	/* Action bar                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Wire one of the buttons FreePBX renders from getActionBar().
	 *
	 * They are drawn outside this module's markup, by the page around it, and
	 * on some versions after this script has already run -- so the click is
	 * caught on the document rather than bound to the node.
	 */
	function onAction(name, handler) {
		document.addEventListener('click', function (event) {
			var target = event.target;

			if (!target || typeof target.closest !== 'function') {
				return;
			}

			var button = target.closest('#' + name + ', [name="' + name + '"]');

			if (!button) {
				return;
			}

			event.preventDefault();
			handler(event);
		});
	}

	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.shared = {
		ajaxUrl: AJAX,
		module: MODULE,
		namePattern: NAME_RE,
		bytes: bytes,
		clock: clock,
		when: when,
		escapeHtml: escapeHtml,
		toast: toast,
		post: post,
		command: command,
		pageUrl: pageUrl,
		listUrl: listUrl,
		editUrl: editUrl,
		playUrl: playUrl,
		go: go,
		onAction: onAction
	};
})(window, document);
