/*
 * tts.js -- the Text to Speech half of the Create Media section.
 *
 * This file is deliberately thin. Synthesis happens on the server: the browser
 * sends text, a voice *key* and a sample rate, and gets back a saved recording.
 * It never sees a model path, a binary path or a command line, and there is
 * nothing here that could supply one.
 *
 * Everything after the file exists -- the saved list, the play endpoint, the
 * delete button, the name rule -- is recorder.js's, reached through
 * OrykMedia.shared rather than reimplemented.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var TTS = CFG.tts || {};

	var el = {};
	var shared = null;
	var generating = false;

	function $(id) {
		return document.getElementById(id);
	}

	function show(node, on) {
		if (node) {
			node.classList.toggle('hidden', !on);
		}
	}

	function text(node, value) {
		if (node) {
			node.textContent = value;
		}
	}

	function state(message, kind) {
		if (!el.state) {
			return;
		}

		text(el.state, message);
		el.state.className = 'oryk-save-state' + (kind ? ' is-' + kind : '');
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Bootstrap's tab plugin would do this, but relying on it means relying on
	 * whichever bootstrap.js the surrounding FreePBX page happened to load.
	 * Two class toggles are cheaper than that dependency.
	 */
	function wireTabs() {
		var tabs = document.querySelectorAll('.oryk-methods [data-oryk-tab]');

		if (!tabs.length) {
			return;
		}

		Array.prototype.forEach.call(tabs, function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();

				var target = link.getAttribute('data-oryk-tab');

				Array.prototype.forEach.call(tabs, function (other) {
					other.parentNode.classList.toggle(
						'active',
						other.getAttribute('data-oryk-tab') === target
					);
				});

				Array.prototype.forEach.call(
					document.querySelectorAll('.oryk-method-panels .tab-pane'),
					function (pane) {
						pane.classList.toggle('active', pane.id === target);
					}
				);

				// The scope canvas has just been given a width for the first
				// time, or has just lost the one it was drawn at.
				if (shared && shared.redraw) {
					shared.redraw();
				}
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Generating                                                          */
	/* ------------------------------------------------------------------ */

	function countCharacters() {
		var used = (el.text.value || '').length;

		text(el.count, String(used));

		if (el.count) {
			el.count.classList.toggle('is-bad', used >= (TTS.maxChars || 5000));
		}
	}

	/**
	 * Play what was just written. The file is served by the same endpoint the
	 * saved list uses; the timestamp is only there to defeat the browser cache
	 * when the same name is regenerated.
	 */
	function preview(name, meta) {
		var url = shared.ajaxUrl + '?module=' + shared.module +
			'&command=play&name=' + encodeURIComponent(name) +
			'&t=' + Date.now();

		el.preview.src = url;
		text(el.meta, meta);
		show(el.review, true);
	}

	function generate(overwrite) {
		if (generating) {
			return;
		}

		var body = (el.text.value || '').trim();
		var name = (el.name.value || '').trim();

		if (!body) {
			state('Enter some text to speak.', 'bad');
			el.text.focus();
			return;
		}

		if (!shared.namePattern.test(name)) {
			state('Name must be letters, numbers, dot, dash or underscore.', 'bad');
			el.name.focus();
			return;
		}

		var form = new FormData();

		form.append('module', shared.module);
		form.append('command', 'generateTts');
		form.append('name', name);
		form.append('text', body);
		form.append('voice', el.voice.value);
		form.append('rate', el.rate.value);
		form.append('overwrite', overwrite ? 'true' : 'false');

		generating = true;
		el.generate.disabled = true;
		show(el.review, false);
		state('Generating… this can take a moment for long text.');

		shared.post(form).then(function (res) {
			generating = false;
			el.generate.disabled = false;

			if (res.status) {
				state(res.message || 'Saved', 'good');
				shared.render(res.recordings || []);
				shared.toast(res.message || 'Saved', 'success');
				preview(res.name, [
					'custom/' + res.name,
					(res.seconds || 0) + ' s',
					shared.bytes(res.bytes || 0),
					((res.rate || 8000) / 1000) + ' kHz'
				].join(' · '));
				return;
			}

			if (res.exists) {
				// Same handshake as the recorder: an existing recording is only
				// replaced when someone says so a second time.
				el.state.className = 'oryk-save-state is-bad';
				el.state.innerHTML = shared.escapeHtml(res.message || 'That name is taken.') +
					' <button type="button" class="btn btn-xs btn-warning" data-oryk-tts-overwrite="1">' +
					'Replace it</button>';
				return;
			}

			state(res.message || 'Could not generate that.', 'bad');
		}).catch(function (error) {
			generating = false;
			el.generate.disabled = false;
			state(error.message || 'Could not reach the server.', 'bad');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		// Tabs exist whether or not Piper does -- the panel still has to be
		// reachable to show why it is empty.
		wireTabs();

		shared = window.OrykMedia && window.OrykMedia.shared;

		if (!shared || !TTS.available) {
			return;
		}

		el = {
			text: $('orykTtsText'),
			count: $('orykTtsCount'),
			voice: $('orykTtsVoice'),
			rate: $('orykTtsRate'),
			name: $('orykTtsName'),
			generate: $('orykTtsGenerate'),
			state: $('orykTtsState'),
			review: $('orykTtsReview'),
			preview: $('orykTtsPreview'),
			meta: $('orykTtsMeta'),
			error: $('orykTtsError')
		};

		// Same guard as recorder.js: FreePBX can swap a module page in without
		// a document load, which runs this file a second time against the same
		// buttons. The claim is staked on the node, where both copies see it.
		if (!el.generate || el.generate.getAttribute('data-oryk-bound') === '1') {
			return;
		}

		el.generate.setAttribute('data-oryk-bound', '1');

		countCharacters();

		el.text.addEventListener('input', countCharacters);

		el.generate.addEventListener('click', function () {
			generate(false);
		});

		el.state.addEventListener('click', function (event) {
			if (event.target.getAttribute('data-oryk-tts-overwrite')) {
				generate(true);
			}
		});

		// Regenerating under a name that was just written should not leave the
		// previous take playing underneath the new one.
		el.name.addEventListener('input', function () {
			if (!el.preview.paused) {
				el.preview.pause();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
