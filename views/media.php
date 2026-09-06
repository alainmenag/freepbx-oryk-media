<?php
/**
 * views/media.php
 *
 * @var array|null $user
 * @var string     $customDir
 * @var bool       $writable
 * @var int        $maxBytes
 * @var array      $recordings
 * @var callable   $assetUrl
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

		<div class="display full-border">

			<div class="section-title">
				<h2><span class="title">Record</span></h2>
			</div>

			<div class="section">

				<div id="orykMediaInsecure" class="alert alert-warning hidden">
					<strong>Microphone unavailable.</strong>
					Browsers only hand out the microphone on a secure origin. Reach this
					page over <code>https://</code> (or <code>http://localhost</code>) and
					reload.
				</div>

				<div id="orykMediaError" class="alert alert-danger hidden"></div>

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="orykMediaDevice">Microphone</label>
							</div>
							<div class="col-md-8">
								<select id="orykMediaDevice" class="form-control">
									<option value="">Default input</option>
								</select>
							</div>
						</div>
					</div>
				</div>

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="orykMediaRate">Sample rate</label>
							</div>
							<div class="col-md-8">
								<select id="orykMediaRate" class="form-control">
									<option value="8000" selected>8 kHz &mdash; standard telephony</option>
									<option value="16000">16 kHz &mdash; wideband (G.722)</option>
								</select>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								Saved as 16-bit mono PCM WAV, which Asterisk plays as-is.
							</span>
						</div>
					</div>
				</div>

				<div class="oryk-recorder">
					<div class="oryk-recorder-controls">
						<button type="button" id="orykMediaRecord" class="btn btn-danger btn-lg">
							<i class="fa fa-circle"></i> <span>Record</span>
						</button>
						<button type="button" id="orykMediaPause" class="btn btn-default btn-lg hidden">
							<i class="fa fa-pause"></i> <span>Pause</span>
						</button>
						<span id="orykMediaTimer" class="oryk-timer">00:00:00:00</span>
					</div>

					<div class="oryk-meter" aria-hidden="true">
						<div id="orykMediaMeterFill" class="oryk-meter-fill"></div>
					</div>

					<canvas id="orykMediaScope" class="oryk-scope" height="90"></canvas>
				</div>

				<div id="orykMediaReview" class="oryk-review hidden">
					<audio id="orykMediaPreview" controls class="oryk-preview"></audio>

					<div id="orykMediaState" class="oryk-state">Ready</div>

					<div id="orykMediaSaveForm">
					<div class="element-container">
						<div class="row">
							<div class="form-group">
								<div class="col-md-4">
									<label class="control-label" for="orykMediaName">Save as</label>
								</div>
								<div class="col-md-8">
									<div class="input-group">
										<input type="text" id="orykMediaName" class="form-control"
											placeholder="main-greeting" autocomplete="off">
										<span class="input-group-addon">.wav</span>
									</div>
								</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-12">
								<span class="help-block fpbx-help-block">
									Letters, numbers, dot, dash and underscore. Lands in
									<code><?php echo $h($customDir); ?></code> and shows up in
									System Recordings as <code>custom/&lt;name&gt;</code>.
								</span>
							</div>
						</div>
					</div>

					<div class="oryk-review-actions">
						<button type="button" id="orykMediaSave" class="btn btn-primary">
							<i class="fa fa-save"></i> Save
						</button>
						<button type="button" id="orykMediaDownload" class="btn btn-default">
							<i class="fa fa-download"></i> Download
						</button>
						<button type="button" id="orykMediaDiscard" class="btn btn-link">
							Discard
						</button>
						<span id="orykMediaSaveState" class="oryk-save-state"></span>
					</div>
					</div>
				</div>

			</div>

			<div class="section-title">
				<h2><span class="title">Saved recordings</span></h2>
			</div>

			<div class="section">
				<table class="table table-striped oryk-list">
					<thead>
						<tr>
							<th>Name</th>
							<th>Formats</th>
							<th>Size</th>
							<th>Modified</th>
							<th class="text-right">&nbsp;</th>
						</tr>
					</thead>
					<tbody id="orykMediaList"></tbody>
				</table>
			</div>

		</div>
	</div>
</div>

<script type="text/javascript">
	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.config = {
		maxBytes: <?php echo (int) $maxBytes; ?>,
		customDir: <?php echo json_encode($customDir); ?>,
		writable: <?php echo $writable ? 'true' : 'false'; ?>,
		recordings: <?php echo json_encode($recordings); ?>
	};
</script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/recorder.js')); ?>"></script>
