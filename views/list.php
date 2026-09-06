<?php
/**
 * views/list.php -- what already exists.
 *
 * Reached at ?display=oryk_media, with no ?edit=. Everything on this page acts
 * on a recording that is already on disk; making one is views/edit.php, which
 * the Add button in the action bar goes to.
 *
 * The rows are drawn by list.js from the JSON below rather than by PHP: a
 * delete answers with the new list, so one renderer paints both the first load
 * and every change after it.
 *
 * @var string   $customDir
 * @var bool     $writable
 * @var array    $recordings
 * @var string   $saved       Name just written by the editor, highlighted here
 * @var callable $assetUrl
 */
$h = function ($v) {
	return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<link rel="stylesheet" href="<?php echo $h($assetUrl('css/media.css')); ?>">

<div class="container-fluid oryk-media">
	<div class="fpbx-container">

		<?php if (!$writable): ?>
			<div class="alert alert-danger">
				<strong>Not writable.</strong>
				Recordings are saved to <code><?php echo $h($customDir); ?></code>, which this
				process cannot write to. Fix ownership on that directory
				(<code>asterisk:asterisk</code>) before recording.
			</div>
		<?php endif; ?>

		<div class="bootstrap-table bootstrap4">

			<div class="fixed-table-toolbar">
				<h2><span class="title">Media</span></h2>
			</div>

			<div class="fixed-table-container">
				<div class="fixed-table-body">
					<table class="table table-striped table-bordered table-hover">
						<thead>
							<tr>
								<th><div class="th-inner">Name</div></th>
								<th><div class="th-inner">Formats</div></th>
								<th><div class="th-inner">Size</div></th>
								<th><div class="th-inner">Modified</div></th>
								<th><div class="th-inner">&nbsp;</div></th>
							</tr>
						</thead>
						<tbody id="orykMediaList">
							<tr><td colspan="5" class="text-muted">Loading…</td></tr>
						</tbody>
					</table>
				</div>
			</div>

		</div>
	</div>
</div>

<script type="text/javascript">
	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.config = {
		customDir: <?php echo json_encode($customDir); ?>,
		writable: <?php echo $writable ? 'true' : 'false'; ?>,
		recordings: <?php echo json_encode($recordings); ?>,
		saved: <?php echo json_encode($saved); ?>
	};
</script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/shared.js')); ?>"></script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/list.js')); ?>"></script>
