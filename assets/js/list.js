/*
 * list.js -- the recordings table on views/list.php.
 *
 * This page owns everything about a recording that already exists: playing it,
 * sending it to the editor, deleting it. Making one is the other page's job,
 * and nothing here knows how.
 *
 * The table is bootstrap-table with server-side pagination, the same as the
 * devices table in oryk_connect. Search, sort and paging are questions the
 * table asks the server, so a delete only has to say "refresh" -- it never
 * has to know what page or search was in force, and neither does this file.
 *
 * The cell formatters are globals because that is how bootstrap-table resolves
 * data-formatter, and they are prefixed rather than named for their column so
 * two modules on one page cannot collide.
 */
(function (window, document, $) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var S = window.OrykMedia && window.OrykMedia.shared;

	var TABLE = '#oryk_recording_table';

	// One namespace for everything this file binds on the document, so a
	// second run can take its own handlers off before putting them back.
	var NS = '.orykMediaList';

	/* ------------------------------------------------------------------ */
	/* Cell formatters                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * The name doubles as the link into the editor -- it is the recording's
	 * identity, so it is also its address. The empty note div underneath is
	 * where a refused delete explains itself.
	 */
	window.orykFmtName = function (value, row) {
		return '<a href="' + S.escapeHtml(S.editUrl(row.name)) + '">' +
			'<code>custom/' + S.escapeHtml(row.name) + '</code></a>' +
			'<div class="oryk-row-note"></div>';
	};

	window.orykFmtFormats = function (value) {
		return S.escapeHtml((value || []).join(', '));
	};

	window.orykFmtSize = function (value) {
		return S.bytes(value);
	};

	window.orykFmtModified = function (value) {
		return S.escapeHtml(S.when(value));
	};

	window.orykFmtActions = function (value, row) {
		return '<div class="oryk-row-actions">' +
			(row.playable
				? '<audio controls preload="none" src="' + S.escapeHtml(S.playUrl(row.name)) + '"></audio>'
				: '<span class="text-muted">not previewable</span>') +
			' <a class="btn btn-default btn-sm" href="' + S.escapeHtml(S.editUrl(row.name)) + '" role="button">' +
			'<i class="fa fa-pencil"></i> Edit</a>' +
			' <button type="button" class="btn btn-default btn-sm" data-oryk-delete="' +
			S.escapeHtml(row.name) + '" title="Delete"><i class="fa fa-trash" style="margin: 0;"></i></button>' +
			'</div>';
	};

	/** Marks the row the editor just wrote, so a save that navigated away lands somewhere visible. */
	window.orykRowStyle = function (row) {
		return row.name === CFG.saved ? { classes: 'oryk-row-new' } : {};
	};

	/* ------------------------------------------------------------------ */
	/* Deleting                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * A recording an IVR points at is refused the first time and only removed
	 * on an explicit "Delete anyway" -- the module knows the file is in use,
	 * and the person clicking is the one who knows whether that matters.
	 *
	 * $row is the <tr> the click came from; the refusal is written into that
	 * row rather than looked up by name, so nothing here depends on which page
	 * of which search the row is currently sitting on.
	 */
	function remove(name, force, $row) {
		S.post(S.command('delete', { name: name, force: force ? 'true' : 'false' }))
			.then(function (res) {
				if (res.status) {
					$(TABLE).bootstrapTable('refresh', { silent: true });
					S.toast(res.message || 'Deleted', 'success');
					return;
				}

				$row.find('.oryk-row-note').html(
					S.escapeHtml(res.message || 'Could not delete.') +
					(res.inUse
						? ' <button type="button" class="btn btn-danger btn-xs" data-oryk-force="' +
							S.escapeHtml(name) + '">Delete anyway</button>'
						: '')
				);

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
		if (!S || !$(TABLE).length) {
			return;
		}

		if (CFG.saved && !$(TABLE).attr('data-oryk-greeted')) {
			$(TABLE).attr('data-oryk-greeted', '1');
			S.toast('Saved custom/' + CFG.saved, 'success');
		}

		S.onAction('orykadd', function () {
			S.go(S.editUrl(''));
		});

		// Delegated on the document: bootstrap-table replaces the whole tbody
		// on every search, sort and page, so anything bound to a row would be
		// gone by the second interaction.
		//
		// Unbound first, because FreePBX can swap a module page in without a
		// document load and run this file a second time. Two handlers on one
		// click is not a doubled action here, it is a *different* one: the
		// first arms the delete button, and the second reads it as armed and
		// deletes on the spot -- the confirm step vanishes rather than
		// misfiring, which is the worst way for it to break.
		$(document).off('click' + NS);

		$(document).on('click' + NS, '[data-oryk-force]', function () {
			remove($(this).attr('data-oryk-force'), true, $(this).closest('tr'));
		});

		$(document).on('click' + NS, '[data-oryk-delete]', function () {
			var button = $(this);
			var name = button.attr('data-oryk-delete');

			// Two-step confirm in place of a modal: the second click commits,
			// and the button disarms itself if it does not come.
			if (button.attr('data-oryk-armed')) {
				remove(name, false, button.closest('tr'));
				return;
			}

			button
				.attr('data-oryk-armed', '1')
				.attr('class', 'btn btn-danger btn-sm')
				.html('Really delete?');

			window.setTimeout(function () {
				if (button.closest('body').length) {
					button
						.removeAttr('data-oryk-armed')
						.attr('class', 'btn btn-default btn-sm')
						.html('<i class="fa fa-trash" style="margin: 0;"></i>');
				}
			}, 4000);
		});
	}

	$(init);
})(window, document, jQuery);
