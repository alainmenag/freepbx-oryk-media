/*
 * editor.js -- the recording editor: one temporary WAV, however it was made.
 *
 * The page offers two ways of producing audio -- the microphone (recorder.js)
 * and Piper text to speech (tts.js) -- and exactly one thing to do with what
 * they produce. That one thing lives here: the current take, the player it is
 * auditioned in, the name it will be filed under, the output sample rate both
 * sources encode to, and the action bar's Save, Download and Close.
 *
 * Neither source knows what becomes of a take. They call setTake() and stop.
 * Nothing reaches the sounds directory until Save, and Download never does.
 *
 * Loaded after shared.js and before the two producers, so both find
 * window.OrykMedia.editor already there.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var S = window.OrykMedia && window.OrykMedia.shared;

	/** Matches Tts::DEFAULT_RATE, and the first option in the markup. */
	var DEFAULT_RATE = 8000;

	var el = {};

	/*
	 * The current recording: the encoded WAV, the object URL the player is
	 * pointed at, the rate it was actually made at, and whether it is a
	 * finished take or the paused monitor of one still being recorded. Null
	 * when there is nothing to hear.
	 */
	var take = null;

	/** A name for a take nobody named, made once so Save and Download agree. */
	var generated = '';

	var saving = false;
	var leaving = false;     // a save is navigating away; do not warn about it
	var onDiscardHandlers = [];

	/*
	 * The recording this page is replacing, from ?edit=<name>. Empty on a new
	 * one, and empty too when ?edit= names something that is not there yet --
	 * then the name is only a prefill, and Save is a first write, not a
	 * replacement.
	 */
	var editing = CFG.editing || '';

	function $(id) {
		return document.getElementById(id);
	}

	function show(node, on) {
		if (node) {
			node.classList.toggle('hidden', !on);
		}
	}

	/* ------------------------------------------------------------------ */
	/* The shared output settings                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * The sample rate both sources encode to. One control for the editor: the
	 * microphone resamples to it in the browser, Piper is resampled to it by
	 * sox on the server, and either way the temporary WAV comes out at it.
	 */
	function rate() {
		return (el.rate && parseInt(el.rate.value, 10)) || DEFAULT_RATE;
	}

	/**
	 * What this take will be filed as.
	 *
	 * An empty box is not an error. An unnamed recording gets a timestamp,
	 * which is unique, safe under the same name rule the server enforces, and
	 * sorts sensibly in the list. Generated once per take so that downloading
	 * it and then saving it do not disagree about what it is called.
	 */
	function name() {
		var typed = (el.name && el.name.value || '').trim();

		if (typed !== '') {
			return typed;
		}

		if (!generated) {
			generated = S.stampName('recording');
		}

		return generated;
	}

	function status(message, kind) {
		if (!el.status) {
			return;
		}

		el.status.textContent = message || '';
		el.status.className = 'oryk-save-state oryk-status-line' + (kind ? ' is-' + kind : '');
	}

	/* ------------------------------------------------------------------ */
	/* The current take                                                    */
	/* ------------------------------------------------------------------ */

	function describe(current) {
		if (!current.saveable) {
			return S.clock(current.seconds) + ' so far · paused';
		}

		return [
			S.clock(current.seconds),
			S.bytes(current.blob.size),
			(current.rate / 1000) + ' kHz'
		].join(' · ');
	}

	/** Stop the player and let go of the blob behind it. */
	function release() {
		if (!take) {
			return;
		}

		if (el.preview) {
			if (!el.preview.paused) {
				el.preview.pause();
			}

			el.preview.removeAttribute('src');
		}

		URL.revokeObjectURL(take.url);
		take = null;
	}

	/**
	 * Replace whatever is loaded with this WAV. Either source can call it, and
	 * either source calling it discards what the other one made -- there is
	 * one current recording, not one per tab.
	 *
	 * `saveable` false is the microphone's paused monitor: worth hearing,
	 * not worth filing, since the take it belongs to is still being recorded.
	 */
	function setTake(blob, meta, saveable) {
		release();

		generated = '';
		take = {
			blob: blob,
			url: URL.createObjectURL(blob),
			rate: (meta && parseInt(meta.rate, 10)) || rate(),
			seconds: (meta && parseFloat(meta.seconds)) || 0,
			saveable: saveable !== false
		};

		el.preview.src = take.url;
		el.meta.textContent = describe(take);

		// A paused monitor is not a recording anyone can do anything with yet:
		// it is about to be recorded over. Play it, do not offer to keep it.
		show(el.actions, take.saveable);
		show(el.review, true);
		status('');
	}

	/**
	 * Drop the current take. Called by either source when it is about to make
	 * another one, so it says nothing to anybody -- discard() is the version
	 * that means a person asked.
	 */
	function clearTake() {
		release();

		generated = '';
		show(el.review, false);
		status('');
	}

	function discard() {
		clearTake();

		onDiscardHandlers.forEach(function (handler) {
			handler();
		});
	}

	/* ------------------------------------------------------------------ */
	/* What the action bar does                                            */
	/* ------------------------------------------------------------------ */

	function save(overwrite) {
		if (saving) {
			return;
		}

		if (!take || !take.saveable) {
			status('Record or generate something first.', 'bad');
			return;
		}

		var target = name();

		if (!S.namePattern.test(target)) {
			status('Name must be letters, numbers, dot, dash or underscore.', 'bad');
			el.name.focus();
			return;
		}

		if (take.blob.size > CFG.maxBytes) {
			status('Recording is too large to upload.', 'bad');
			return;
		}

		// Saving over the file this page was opened on is already a confirmed
		// replacement: it is what the page was opened to do. Any other name
		// still has to be confirmed, whichever tab made the audio.
		var form = S.command('save', {
			name: target,
			overwrite: (overwrite || target === editing) ? 'true' : 'false'
		});

		form.append('audio', take.blob, target + '.wav');

		saving = true;
		status('Saving…');

		S.post(form).then(function (res) {
			saving = false;

			if (res.status) {
				// Saved: this page is done. The list is where a recording is
				// played, re-recorded or deleted.
				leaving = true;
				status(res.message || 'Saved', 'good');
				S.go(S.listUrl(res.name || target));
				return;
			}

			if (res.exists) {
				// The overwrite handshake, once, for both sources.
				el.status.className = 'oryk-save-state oryk-status-line is-bad';
				el.status.innerHTML = S.escapeHtml(res.message || 'That name is taken.') +
					' <button type="button" class="btn btn-xs btn-warning" data-oryk-overwrite="1">Overwrite</button>';
				return;
			}

			status(res.message || 'Could not save.', 'bad');
		}).catch(function (error) {
			saving = false;
			status(error.message || 'Could not reach the server.', 'bad');
		});
	}

	/**
	 * Hand the temporary WAV to the browser. It never touches the server:
	 * the blob is already here, and the anchor points at the same object URL
	 * the player is using.
	 */
	function download() {
		if (!take) {
			status('Record or generate something first.', 'bad');
			return;
		}

		var filename = name() + '.wav';
		var link = document.createElement('a');

		link.href = take.url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);

		status('Downloaded ' + filename + '. Nothing was written to the server.', 'good');
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Bootstrap's tab plugin would do this, but relying on it means relying on
	 * whichever bootstrap.js the surrounding FreePBX page happened to load.
	 * Two class toggles are cheaper than that dependency.
	 *
	 * The tabs are only sources now -- the take, the name and the sample rate
	 * all sit outside them -- so switching tabs changes nothing but which
	 * producer is showing.
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
				var recorder = window.OrykMedia.recorder;

				if (recorder && recorder.redraw) {
					recorder.redraw();
				}
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		if (!S) {
			return;
		}

		// Tabs exist whether or not there is anything else to bind: the panes
		// still have to be reachable to explain themselves.
		wireTabs();

		// FreePBX can swap a module page in without a document load, which
		// re-executes this file against the same nodes. The claim is staked on
		// a node, where both copies can see it.
		var anchor = $('orykMediaName');

		if (!anchor || anchor.getAttribute('data-oryk-bound') === '1') {
			return;
		}

		anchor.setAttribute('data-oryk-bound', '1');

		el = {
			rate: $('orykMediaRate'),
			name: anchor,
			review: $('orykMediaReview'),
			preview: $('orykMediaPreview'),
			meta: $('orykMediaMeta'),
			actions: $('orykMediaTakeActions'),
			discard: $('orykMediaDiscard'),
			status: $('orykMediaStatus')
		};

		// The action bar is drawn by FreePBX, outside this module's markup and
		// sometimes after this script has run, so the clicks are caught on the
		// document. One Save, one Download: what they act on is the current
		// recording, not whichever tab happens to be open.
		S.onAction('oryksave', function () {
			save(false);
		});

		S.onAction('orykdownload', download);

		S.onAction('orykclose', function () {
			S.go(S.listUrl());
		});

		el.discard.addEventListener('click', discard);

		el.status.addEventListener('click', function (event) {
			if (event.target.getAttribute('data-oryk-overwrite')) {
				save(true);
			}
		});

		// A take carries the rate it was encoded at, so changing the setting
		// afterwards does not change the audio -- say so rather than saving
		// something that quietly disagrees with the control above it.
		el.rate.addEventListener('change', function () {
			if (take && take.rate !== rate()) {
				status('Output is now ' + (rate() / 1000) +
					' kHz. Record or generate again to apply it to this take.');
			}
		});

		window.addEventListener('beforeunload', function (event) {
			var recorder = window.OrykMedia.recorder;
			var busy = recorder && recorder.isRecording && recorder.isRecording();

			if (leaving || !(take || busy)) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		});
	}

	/* ------------------------------------------------------------------ */
	/* What the two sources use                                            */
	/* ------------------------------------------------------------------ */

	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.editor = {
		rate: rate,
		status: status,
		setTake: setTake,
		clearTake: clearTake,
		hasTake: function () {
			return !!take;
		},
		/**
		 * Run when a person discards the take -- the mic resets its clock.
		 *
		 * Deliberately not fired by clearTake(): a source clearing the take to
		 * make another one is not a discard, and resuming a paused recording
		 * does exactly that. Resetting the clock there would throw away the
		 * elapsed time of a take still being recorded.
		 */
		onDiscard: function (handler) {
			onDiscardHandlers.push(handler);
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
