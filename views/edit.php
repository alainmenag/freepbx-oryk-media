<?php
/**
 * views/edit.php -- make one recording.
 *
 * Reached at ?display=oryk_media&edit=<name> to replace an existing recording,
 * or ?display=oryk_media&edit= (present, empty) to write a new one.
 *
 * The page is one recording editor with two sources on tabs: the browser
 * microphone, and local Piper text to speech. Either produces the same thing --
 * a temporary WAV held in the browser -- and everything after that is shared:
 * one player, one name, one output sample rate, and the action bar's Save,
 * Download and Close. Nothing is written to the sounds directory until Save.
 *
 * Nothing here lists, plays back or deletes what already exists -- that is
 * views/list.php, which Close and a finished Save both return to.
 *
 * @var string      $customDir
 * @var bool        $writable
 * @var int         $maxBytes
 * @var string      $editing   Existing recording being replaced, '' for a new one
 * @var string      $prefill   Name to start the box with (may be a name not yet used)
 * @var bool        $playable  Whether $editing can be auditioned in a browser
 * @var array       $usage     System Recordings entries pointing at $editing
 * @var array       $tts       Oryk_media::ttsStatus() -- `checks` only for admins
 * @var callable    $assetUrl
 */
$h = function ($v) {
	return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$ttsReady = !empty($tts['available']);
$playUrl = 'ajax.php?module=oryk_media&command=play&name=' . rawurlencode($editing);
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
				<h2>
					<span class="title">
						<?php if ($editing !== ''): ?>
							Edit <code><?php echo $h('custom/' . $editing); ?></code>
						<?php else: ?>
							New recording
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section">

				<?php if ($editing !== ''): ?>
					<div class="oryk-editing">
						<div class="oryk-editing-head">
							<strong>Replacing <code><?php echo $h('custom/' . $editing); ?></code>.</strong>
							Record or generate below, then Save &mdash; it is written over the
							existing file, under the same name.
							<a href="?display=oryk_media&amp;edit=">Make a new one instead</a>.
						</div>

						<?php if ($playable): ?>
							<audio controls preload="none" class="oryk-preview oryk-editing-audio"
								src="<?php echo $h($playUrl); ?>"></audio>
						<?php endif; ?>

						<?php if (!empty($usage)): ?>
							<div class="oryk-editing-usage">
								In use by System Recordings:
								<?php echo $h(implode(', ', $usage)); ?>.
								Whatever you save here is what those play.
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- ---------------------------------------------------- -->
				<!-- Output: one name and one sample rate for the editor, -->
				<!-- whichever tab below made the audio.                  -->
				<!-- ---------------------------------------------------- -->

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="orykMediaName">Save as</label>
							</div>
							<div class="col-md-8">
								<div class="input-group">
									<input type="text" id="orykMediaName" class="form-control"
										placeholder="main-greeting" autocomplete="off"
										value="<?php echo $h($prefill); ?>">
									<span class="input-group-addon">.wav</span>
								</div>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								Optional &mdash; leave it blank and it is saved as
								<code>recording-YYYYMMDD-HHMMSS</code>. Letters, numbers, dot,
								dash and underscore. Lands in
								<code><?php echo $h($customDir); ?></code> and shows up in
								System Recordings as <code>custom/&lt;name&gt;</code>.
							</span>
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
								Applies to both sources below. 16-bit mono PCM WAV either way,
								which Asterisk plays as-is.
							</span>
						</div>
					</div>
				</div>

				<ul class="nav nav-tabs oryk-methods" role="tablist">
					<li role="presentation" class="active">
						<a href="#orykMediaMic" data-oryk-tab="orykMediaMic" role="tab">
							<i class="fa fa-microphone"></i> Record from Microphone
						</a>
					</li>
					<li role="presentation">
						<a href="#orykMediaTts" data-oryk-tab="orykMediaTts" role="tab">
							<i class="fa fa-keyboard-o"></i> Text to Speech
						</a>
					</li>
				</ul>

				<div class="tab-content oryk-method-panels">

					<!-- ---------------------------------------------------- -->
					<!-- Source 1: microphone                                 -->
					<!-- ---------------------------------------------------- -->

					<div class="tab-pane active" id="orykMediaMic" role="tabpanel">

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

						<div class="oryk-recorder">
							<div class="oryk-meter" aria-hidden="true">
								<div id="orykMediaMeterFill" class="oryk-meter-fill"></div>
							</div>

							<canvas id="orykMediaScope" class="oryk-scope" height="90"
								title="Click to open the microphone and check levels"></canvas>

							<div class="oryk-recorder-controls">
								<button type="button" id="orykMediaRecord" class="btn btn-danger btn-lg">
									<i class="fa fa-circle"></i> <span>Record</span>
								</button>
								<button type="button" id="orykMediaPause" class="btn btn-default btn-lg hidden">
									<i class="fa fa-pause"></i> <span>Pause</span>
								</button>
								<span id="orykMediaTimer" class="oryk-timer">00:00:00:00</span>
							</div>
						</div>

					</div>

					<!-- ---------------------------------------------------- -->
					<!-- Source 2: Piper text to speech                       -->
					<!-- ---------------------------------------------------- -->

					<div class="tab-pane" id="orykMediaTts" role="tabpanel">

						<?php if (!$ttsReady): ?>

							<div class="alert alert-warning">
								<strong>Text to Speech is unavailable on this system.</strong>
								Microphone recording is unaffected.
							</div>

							<?php if (!empty($tts['checks'])): ?>
								<p class="help-block fpbx-help-block">
									Administrator diagnostics &mdash; run
									<code>install/fetch-piper.sh</code> in the module directory to
									install the Piper runtime and the default voice.
								</p>
								<table class="table table-condensed oryk-tts-checks">
									<tbody>
										<?php foreach ($tts['checks'] as $check): ?>
											<tr class="<?php echo empty($check['ok']) ? 'oryk-check-bad' : 'oryk-check-ok'; ?>">
												<td class="oryk-check-icon">
													<i class="fa fa-<?php echo empty($check['ok']) ? 'times' : 'check'; ?>"></i>
												</td>
												<td><?php echo $h($check['label']); ?></td>
												<td><code><?php echo $h($check['detail']); ?></code></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>

						<?php else: ?>

							<div class="element-container">
								<div class="row">
									<div class="form-group">
										<div class="col-md-4">
											<label class="control-label" for="orykTtsText">Text</label>
										</div>
										<div class="col-md-8">
											<textarea id="orykTtsText" class="form-control oryk-tts-text"
												rows="6" maxlength="<?php echo (int) $tts['maxChars']; ?>"
												placeholder="Thank you for calling. Please listen carefully, as our menu options have changed."></textarea>
											<span class="help-block fpbx-help-block">
												<span id="orykTtsCount">0</span> /
												<?php echo number_format((int) $tts['maxChars']); ?> characters.
												Punctuation drives the phrasing and the pauses.
											</span>
										</div>
									</div>
								</div>
							</div>

							<div class="element-container">
								<div class="row">
									<div class="form-group">
										<div class="col-md-4">
											<label class="control-label" for="orykTtsVoice">Voice</label>
										</div>
										<div class="col-md-8">
											<select id="orykTtsVoice" class="form-control">
												<?php foreach ($tts['voices'] as $voice): ?>
													<option value="<?php echo $h($voice['id']); ?>">
														<?php echo $h($voice['label']); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>
								</div>
							</div>

							<div class="oryk-review-actions">
								<button type="button" id="orykTtsGenerate" class="btn btn-danger btn-lg">
									<i class="fa fa-play-circle"></i> Generate
								</button>
								<span id="orykMediaStatus" class="help-block fpbx-help-block oryk-inline-help">
									Generated on this server and nothing is sent anywhere.
								</span>
							</div>

						<?php endif; ?>

					</div>

				</div>

				<!-- ---------------------------------------------------- -->
				<!-- The current recording, whichever tab made it.        -->
				<!-- ---------------------------------------------------- -->

				<div id="orykMediaReview" class="oryk-review hidden">
					<audio id="orykMediaPreview" controls class="oryk-preview"></audio>

					<div id="orykMediaMeta" class="oryk-state"></div>

					<div class="oryk-review-actions" id="orykMediaTakeActions">
						<button type="button" id="orykMediaDiscard" class="btn btn-link">
							Discard
						</button>
						<span class="help-block fpbx-help-block oryk-inline-help">
							Not saved yet.
						</span>
					</div>
				</div>

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
		editing: <?php echo json_encode($editing); ?>,
		tts: {
			available: <?php echo $ttsReady ? 'true' : 'false'; ?>,
			maxChars: <?php echo (int) $tts['maxChars']; ?>,
			voices: <?php echo json_encode(array_values($tts['voices'])); ?>
		}
	};
</script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/shared.js')); ?>"></script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/editor.js')); ?>"></script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/recorder.js')); ?>"></script>
<script type="text/javascript" src="<?php echo $h($assetUrl('js/tts.js')); ?>"></script>
