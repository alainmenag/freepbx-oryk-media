/*
 * tts.js -- the Text to Speech source, one of the two ways this page makes a
 * recording.
 *
 * This file is deliberately thin. Synthesis happens on the server: the browser
 * sends text, a voice *key* and the editor's output sample rate, and gets the
 * generated WAV back as bytes. It never sees a model path, a binary path or a
 * command line, and there is nothing here that could supply one.
 *
 * Nothing is written to the sounds directory by generating. What comes back is
 * a temporary WAV, handed to editor.js exactly as a microphone take is -- same
 * player, same name field, same Save. This panel owns the text and the voice,
 * and nothing else.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var TTS = CFG.tts || {};

	var S = window.OrykMedia && window.OrykMedia.shared;
	var E = window.OrykMedia && window.OrykMedia.editor;

	var el = {};
	var generating = false;

	function $(id) {
		return document.getElementById(id);
	}

	function text(node, value) {
		if (node) {
			node.textContent = value;
		}
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
	 * Ask the server to speak the text, and load what comes back.
	 *
	 * No name is involved: the file this becomes is decided at Save, by the
	 * editor, and may never be saved at all. A failure comes back as JSON
	 * where the audio would have been; postBinary() tells the two apart.
	 */
	function generate() {
		if (generating) {
			return;
		}

		var body = (el.text.value || '').trim();

		if (!body) {
			E.status('Enter some text to speak.', 'bad');
			el.text.focus();
			return;
		}

		var form = S.command('generateTts', {
			text: body,
			voice: el.voice.value,
			rate: E.rate()
		});

		generating = true;
		el.generate.disabled = true;

		// The previous take goes now rather than when the new one lands: it is
		// no longer what the page is about, and a long generate would leave it
		// sitting there looking current.
		E.clearTake();
		E.status('Generating… this can take a moment for long text.');

		S.postBinary(form).then(function (res) {
			generating = false;
			el.generate.disabled = false;

			if (res.json) {
				E.status(res.json.message || 'Could not generate that.', 'bad');
				return;
			}

			E.setTake(res.blob, {
				seconds: res.header('X-Oryk-Seconds'),
				rate: res.header('X-Oryk-Rate')
			}, true);

			E.status('Generated. Listen to it, then Save or Download.', 'good');
		}).catch(function (error) {
			generating = false;
			el.generate.disabled = false;
			E.status(error.message || 'Could not reach the server.', 'bad');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		if (!S || !E || !TTS.available) {
			return;
		}

		el = {
			text: $('orykTtsText'),
			count: $('orykTtsCount'),
			voice: $('orykTtsVoice'),
			generate: $('orykTtsGenerate')
		};

		// Same guard as the other panels: FreePBX can swap a module page in
		// without a document load, which runs this file a second time against
		// the same buttons. The claim is staked on the node.
		if (!el.generate || el.generate.getAttribute('data-oryk-bound') === '1') {
			return;
		}

		el.generate.setAttribute('data-oryk-bound', '1');

		countCharacters();

		el.text.addEventListener('input', countCharacters);
		el.generate.addEventListener('click', generate);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
