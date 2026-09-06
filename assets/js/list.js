/*
 * list.js -- the recordings table on views/list.php.
 *
 * This page owns everything about a recording that already exists: playing it,
 * sending it to the editor, deleting it. Making one is the other page's job,
 * and nothing here knows how.
 *
 * The table is drawn from JSON rather than by PHP because a delete answers
 * with the new list, so the same renderer paints the page and every change to
 * it. Two renderers would be two chances to disagree.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var S = window.OrykMedia && window.OrykMedia.shared;

	var el = {};

	function $(id) {
		return document.getElementById(id);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	function row(r) {
		var name = S.escapeHtml(r.name);

		return '<tr data-oryk-row="' + name + '"' +
			(r.name === CFG.saved ? ' class="oryk-row-new"' : '') + '>' +
			'<td><a href="' + S.escapeHtml(S.editUrl(r.name)) + '"><code>custom/' + name + '</code></a>' +
			'<div class="oryk-row-note"></div></td>' +
			'<td>' + S.escapeHtml(r.formats.join(', ')) + '</td>' +
			'<td>' + S.bytes(r.bytes) + '</td>' +
			'<td>' + S.escapeHtml(S.when(r.modified)) + '</td>' +
			'<td class="text-right oryk-row-actions">' +
			(r.playable
				? '<audio controls preload="none" src="' + S.escapeHtml(S.playUrl(r.name)) + '"></audio>'
				: '<span class="text-muted">not previewable</span>') +
			' <a class="btn btn-xs btn-default" href="' + S.escapeHtml(S.editUrl(r.name)) + '">' +
			'<i class="fa fa-pencil"></i> Edit</a>' +
			' <button type="button" class="btn btn-xs btn-default" data-oryk-delete="' + name + '"' +
			' title="Delete"><i class="fa fa-trash"></i></button>' +
			'</td></tr>';
	}

	function render(recordings) {
		if (!el.list) {
			return;
		}

		if (!recordings.length) {
			el.list.innerHTML = '<tr><td colspan="5" class="text-muted">' +
				'No recordings yet. <a href="' + S.escapeHtml(S.editUrl('')) + '">Add one</a>.' +
				'</td></tr>';
			return;
		}

		el.list.innerHTML = recordings.map(row).join('');
	}

	/* ------------------------------------------------------------------ */
	/* Deleting                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * A recording an IVR points at is refused the first time and only removed
	 * on an explicit "Delete anyway" -- the module knows the file is in use,
	 * and the person clicking is the one who knows whether that matters.
	 */
	function remove(name, force) {
		S.post(S.command('delete', { name: name, force: force ? 'true' : 'false' }))
			.then(function (res) {
				if (res.status) {
					render(res.recordings || []);
					S.toast(res.message || 'Deleted', 'success');
					return;
				}

				var note = el.list.querySelector('[data-oryk-row="' + name + '"] .oryk-row-note');

				if (note) {
					note.innerHTML = S.escapeHtml(res.message || 'Could not delete.') +
						(res.inUse
							? ' <button type="button" class="btn btn-xs btn-danger" data-oryk-force="' +
								S.escapeHtml(name) + '">Delete anyway</button>'
							: '');
				}

				S.toast(res.message || 'Could not delete', 'error');
			})
			.catch(function (error) {
				S.toast(error.message, 'error');
			});
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		el.list = $('orykMediaList');

		// FreePBX can swap a module page in without a document load, which runs
		// this file again against the same table. The claim is staked on the
		// node, where both copies can see it.
		if (!S || !el.list || el.list.getAttribute('data-oryk-bound') === '1') {
			return;
		}

		el.list.setAttribute('data-oryk-bound', '1');

		render(CFG.recordings || []);

		if (CFG.saved) {
			S.toast('Saved custom/' + CFG.saved, 'success');
		}

		S.onAction('orykadd', function () {
			S.go(S.editUrl(''));
		});

		el.list.addEventListener('click', function (event) {
			var button = event.target.closest('[data-oryk-delete], [data-oryk-force]');

			if (!button) {
				return;
			}

			var force = button.getAttribute('data-oryk-force');

			if (force) {
				remove(force, true);
				return;
			}

			var name = button.getAttribute('data-oryk-delete');

			// Two-step confirm in place of a modal: the second click commits,
			// and the button disarms itself if it does not come.
			if (button.getAttribute('data-oryk-armed')) {
				remove(name, false);
				return;
			}

			button.setAttribute('data-oryk-armed', '1');
			button.className = 'btn btn-xs btn-danger';
			button.innerHTML = 'Really delete?';

			window.setTimeout(function () {
				if (button.parentNode) {
					button.removeAttribute('data-oryk-armed');
					button.className = 'btn btn-xs btn-default';
					button.innerHTML = '<i class="fa fa-trash"></i>';
				}
			}, 4000);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
