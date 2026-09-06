<?php
/**
 * views/list.php -- what already exists.
 *
 * Reached at ?display=oryk_media, with no ?edit=. Everything on this page acts
 * on a recording that is already on disk; making one is views/edit.php, which
 * the Add button in the action bar goes to.
 *
 * The table is bootstrap-table against ajax.php, the same as the devices table
 * in oryk_connect: search, sort and paging are the server's answers rather
 * than this page's, so a delete only has to say "refresh". No rows are
 * rendered here, and the toolbar and pagination chrome are the plugin's own --
 * the markup below is only the parts it does not generate.
 *
 * @var string   $customDir
 * @var bool     $writable
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

		<!-- Handed to the table as data-toolbar, so the plugin lifts it into
		     its own toolbar row and the title sits level with the search box
		     instead of above a second, empty one. -->
		<div id="orykMediaToolbar" class="oryk-toolbar">
			<h2><span class="title">Media</span></h2>
		</div>

		<table
			id="oryk_recording_table"
			data-toggle="table"
			data-url="ajax.php?module=oryk_media&amp;command=list"
			class="table table-striped table-bordered table-hover oryk-list"
			data-toolbar="#orykMediaToolbar"
			data-side-pagination="server"
			data-pagination="true"
			data-search="true"
			data-unique-id="name"
			data-row-style="orykRowStyle"
			data-sort-name="modified"
			data-sort-order="desc">
			<thead>
				<tr>
					<th data-field="name" data-formatter="orykFmtName" data-sortable="true">Name</th>
					<th data-field="formats" data-formatter="orykFmtFormats" data-sortable="true">Formats</th>
					<th data-field="bytes" data-formatter="orykFmtSize" data-sortable="true">Size</th>
					<th data-field="modified" data-formatter="orykFmtModified" data-sortable="true">Modified</th>
					<th data-field="actions" data-formatter="orykFmtActions">Actions</th>
				</tr>
			</thead>
		</table>

	</div>
</div>

<script type="text/javascript">
	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.config = {
		customDir: <?php echo json_encode($customDir); ?>,
		writable: <?php echo $writable ? 'true' : 'false'; ?>,
		saved: <?php echo json_encode($saved); ?>
	};
</script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/shared.js')); ?>"></script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/list.js')); ?>"></script>
