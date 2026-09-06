<?php

// Oryk_media.class.php

namespace FreePBX\modules;

use PDO;
use FreePBX_Helpers;

/**
 * Media -- create audio in the browser and drop it where FreePBX looks for
 * custom sounds, so it can be picked up by System Recordings.
 *
 * There are two ways in. The microphone recorder does all its encoding in the
 * browser: recorder.js captures from the mic, resamples with an
 * OfflineAudioContext and writes a 16-bit mono PCM WAV. That is a format
 * Asterisk plays natively, so that path shells out to nothing and has no
 * runtime dependency beyond PHP.
 *
 * Text to Speech runs Piper locally -- see lib/Tts.php. It is optional: if the
 * bundled runtime or sox is missing, the module says so and the recorder
 * carries on working. Nothing is sent off the machine either way.
 *
 * Both paths end in the same place, as the same kind of file, listed and
 * deleted by the same code.
 *
 * The module is two pages, in the shape the rest of FreePBX uses: a list of
 * what exists, and an editor for one recording. See showPage().
 */
class Oryk_media extends FreePBX_Helpers implements \BMO
{
	/**
	 * What a recording may be called. This ends up as a filename on disk and as
	 * an Asterisk playback path, so it stays deliberately narrow: no spaces, no
	 * slashes, nothing that needs quoting.
	 */
	const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	/** Ceiling on an uploaded recording. ~20 min of 8 kHz mono PCM. */
	const MAX_UPLOAD_BYTES = 20971520;

	/**
	 * Extensions we will list and serve out of the custom sounds directory.
	 * System Recordings leaves several encodings of the same recording side by
	 * side, so a single name can appear here more than once.
	 */
	public static $audioExtensions = [
		'wav', 'WAV', 'ulaw', 'alaw', 'g722', 'g729', 'gsm', 'sln', 'sln16', 'sln48', 'ogg', 'mp3',
	];

	/** Extension we write, and prefer when several exist for one name. */
	const PRIMARY_EXTENSION = 'wav';

	/** @var array|null|false Cached result of getUser() for this request. */
	private $userCache = false;

	/** @var \FreePBX\modules\OrykMedia\Tts|null Built on first use. */
	private $ttsHelper = null;

	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman;
	}

	/**
	 * Two pages, told apart by ?edit=.
	 *
	 *   ?display=oryk_media              the list: what exists
	 *   ?display=oryk_media&edit=<name>  the editor, bound to that recording
	 *   ?display=oryk_media&edit=        the editor, writing a new one
	 *
	 * `edit` present but empty is deliberate rather than a degenerate case: it
	 * is the same page doing the same thing, minus a file to replace. The
	 * parameter is the name itself, not an id, because that name *is* the
	 * identity here -- there is no row anywhere, only a file in a directory.
	 */
	public function showPage()
	{
		if (!isset($_REQUEST['edit'])) {
			return load_view(__DIR__ . '/views/list.php', [
				'customDir' => $this->getCustomDir(),
				'writable' => $this->isCustomDirWritable(),
				'recordings' => $this->listRecordings(),
				'saved' => $this->savedName(),
				'assetUrl' => [$this, 'assetUrl'],
			]);
		}

		$wanted = trim((string) $_REQUEST['edit']);

		// A name that could never exist on disk is a typo or a hand-edited URL,
		// not a recording. Sending it to the editor would open a form bound to
		// something unreachable, so it goes back to the list instead.
		if ($wanted !== '' && !$this->isValidName($wanted)) {
			header('Location: ?display=oryk_media');

			return;
		}

		// A name that is merely not taken yet is a prefill, not a target: Save
		// is then a first write and still has to confirm before replacing
		// anything, exactly as it would from a blank editor.
		$editing = $this->recordingExists($wanted) ? $wanted : '';

		return load_view(__DIR__ . '/views/edit.php', [
			'customDir' => $this->getCustomDir(),
			'writable' => $this->isCustomDirWritable(),
			'maxBytes' => self::MAX_UPLOAD_BYTES,
			'editing' => $editing,
			'prefill' => $wanted,
			'playable' => $editing !== '' && $this->playableExtension($editing) !== null,
			'usage' => $editing !== '' ? $this->getUsage($editing) : [],
			'tts' => $this->ttsStatus(),
			'assetUrl' => [$this, 'assetUrl'],
		]);
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Deliberately not the usual submit/delete names: those are wired by core
	 * to a `form.fpbx-submit`, and neither page has one -- a recording is not a
	 * form post, it is an encoded blob going up over ajax. These are ours, and
	 * shared.js's onAction() binds them.
	 */
	public function getActionBar($request)
	{
		if (!isset($_REQUEST['edit'])) {
			return [
				'orykadd' => [
					'name' => 'orykadd',
					'id' => 'orykadd',
					'value' => _('Add'),
				],
			];
		}

		return [
			'oryksave' => [
				'name' => 'oryksave',
				'id' => 'oryksave',
				'value' => _('Save'),
			],
			'orykclose' => [
				'name' => 'orykclose',
				'id' => 'orykclose',
				'value' => _('Close'),
			],
		];
	}

	/**
	 * Name the editor says it just wrote, for the list to point at.
	 *
	 * It arrives in the URL, so it is checked like any other input before it
	 * reaches a template -- it only ever ends up compared against names the
	 * server itself produced, but that is a property of today's callers.
	 */
	private function savedName()
	{
		$saved = isset($_REQUEST['saved']) ? trim((string) $_REQUEST['saved']) : '';

		return $this->isValidName($saved) ? $saved : '';
	}

	/**
	 * URL for a file under assets/, stamped with its mtime.
	 *
	 * The stamp matters: these are cached hard by the browser, and a stale
	 * recorder.js is not merely old, it can be actively broken against the
	 * markup the page just rendered.
	 */
	public function assetUrl($relative)
	{
		$path = __DIR__ . '/assets/' . ltrim($relative, '/');
		$url = '/admin/assets/oryk_media/' . ltrim($relative, '/');

		$mtime = @filemtime($path);

		return $mtime ? $url . '?v=' . $mtime : $url;
	}

	public function getUser()
	{
		if ($this->userCache !== false) {
			return $this->userCache;
		}

		$this->userCache = null;

		$ampUser = isset($_SESSION['AMP_user']) ? $_SESSION['AMP_user'] : null;
		$username = $ampUser ? $ampUser->username : null;

		if (!$username) {
			return null;
		}

		$sql = "SELECT * FROM ampusers WHERE username = :username";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':username' => $username]);
		$user = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($user) {
			return $this->userCache = array_merge($user, ['admin' => true]);
		}

		$sql = "SELECT * FROM userman_users WHERE username = :username";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':username' => $username]);
		$user = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($user) {
			return $this->userCache = array_merge($user, ['admin' => false]);
		}

		return $this->userCache = null;
	}

	/* ------------------------------------------------------------------ */
	/* Where recordings live                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Root of the Asterisk sounds tree, e.g. /var/lib/asterisk/sounds.
	 */
	public function getSoundsDir()
	{
		$base = '/var/lib/asterisk';

		try {
			$configured = \FreePBX::Config()->get('ASTVARLIBDIR');

			if (!empty($configured)) {
				$base = rtrim($configured, '/');
			}
		} catch (\Throwable $e) {
			// Stock path it is.
		}

		return $base . '/sounds';
	}

	/**
	 * Language subdirectory the custom sounds live under.
	 *
	 * FreePBX files custom recordings as sounds/<lang>/custom/<name>. Ask the
	 * Recordings module which language it is using rather than assuming, but
	 * fall back to 'en', which is what a stock system answers.
	 */
	public function getLanguage()
	{
		try {
			$recordings = $this->FreePBX->Recordings;

			if ($recordings && method_exists($recordings, 'getLanguage')) {
				$lang = $recordings->getLanguage();

				if (is_string($lang) && preg_match('/^[a-z]{2}([_-][A-Za-z0-9]{2,8})?$/', $lang)) {
					return $lang;
				}
			}
		} catch (\Throwable $e) {
			// Recordings not installed or shaped differently on this version.
		}

		return 'en';
	}

	/**
	 * Directory new recordings are written to and existing ones are read from.
	 */
	public function getCustomDir()
	{
		return $this->getSoundsDir() . '/' . $this->getLanguage() . '/custom';
	}

	/**
	 * Create the custom directory if it is not there yet.
	 *
	 * A fresh system that has never had a custom recording will not have it.
	 */
	private function ensureCustomDir()
	{
		$dir = $this->getCustomDir();

		if (is_dir($dir)) {
			return true;
		}

		return @mkdir($dir, 0775, true) && is_dir($dir);
	}

	public function isCustomDirWritable()
	{
		$dir = $this->getCustomDir();

		if (is_dir($dir)) {
			return is_writable($dir);
		}

		// Not there yet -- writable if we could create it.
		$parent = dirname($dir);

		return is_dir($parent) && is_writable($parent);
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Every recording in the custom directory, one entry per name.
	 *
	 * Asterisk keeps parallel encodings of the same recording (foo.wav,
	 * foo.ulaw, foo.sln16 ...). Those are one recording, not three, so they are
	 * folded into a single entry listing the formats present.
	 */
	public function listRecordings()
	{
		$dir = $this->getCustomDir();

		if (!is_dir($dir)) {
			return [];
		}

		$byName = [];

		foreach ((array) @scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..' || !is_file($dir . '/' . $entry)) {
				continue;
			}

			$ext = pathinfo($entry, PATHINFO_EXTENSION);
			$name = pathinfo($entry, PATHINFO_FILENAME);

			if ($ext === '' || !in_array($ext, self::$audioExtensions, true)) {
				continue;
			}

			if (!$this->isValidName($name)) {
				continue;
			}

			$stat = @stat($dir . '/' . $entry);

			if (!isset($byName[$name])) {
				$byName[$name] = [
					'name' => $name,
					'formats' => [],
					'bytes' => 0,
					'modified' => 0,
				];
			}

			$byName[$name]['formats'][] = $ext;
			$byName[$name]['bytes'] += $stat ? (int) $stat['size'] : 0;
			$byName[$name]['modified'] = max($byName[$name]['modified'], $stat ? (int) $stat['mtime'] : 0);
		}

		foreach ($byName as &$entry) {
			sort($entry['formats']);
			$entry['playable'] = $this->playableExtension($entry['name']) !== null;
		}
		unset($entry);

		uasort($byName, function ($a, $b) {
			return $b['modified'] <=> $a['modified'];
		});

		return array_values($byName);
	}

	/**
	 * Extension of the file we would hand a browser for this name, or null if
	 * nothing on disk is something a browser can play.
	 */
	private function playableExtension($name)
	{
		$dir = $this->getCustomDir();

		foreach ([self::PRIMARY_EXTENSION, 'WAV', 'ogg', 'mp3'] as $ext) {
			if (is_file($dir . '/' . $name . '.' . $ext)) {
				return $ext;
			}
		}

		return null;
	}

	public function isValidName($name)
	{
		return is_string($name) && (bool) preg_match(self::NAME_PATTERN, $name);
	}

	public function recordingExists($name)
	{
		if (!$this->isValidName($name)) {
			return false;
		}

		$dir = $this->getCustomDir();

		foreach (self::$audioExtensions as $ext) {
			if (is_file($dir . '/' . $name . '.' . $ext)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * System Recordings entries that point at this file.
	 *
	 * Used to keep a delete from quietly breaking an IVR. Best effort: the
	 * recordings table is owned by another module and has changed shape across
	 * versions, so a failure here means "unknown", not "unused".
	 */
	public function getUsage($name)
	{
		if (!$this->isValidName($name)) {
			return [];
		}

		try {
			$sql = "SELECT displayname FROM recordings WHERE filename LIKE :needle";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':needle' => '%custom/' . $name . '%']);

			return array_column((array) $stmt->fetchAll(PDO::FETCH_ASSOC), 'displayname');
		} catch (\Throwable $e) {
			return [];
		}
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Put a WAV that already exists somewhere on disk into the custom sounds
	 * directory as <name>.wav.
	 *
	 * The last few steps of saving are identical whether the bytes arrived as
	 * a browser upload or came out of Piper a moment ago -- same name rules,
	 * same overwrite handshake, same ownership -- so both callers land here.
	 * The one thing that must differ is how the file is moved:
	 * move_uploaded_file() is what makes an upload safe, and it refuses
	 * anything that was not one.
	 *
	 * @param string $name      Bare name, no extension.
	 * @param string $source    Path to the WAV to store.
	 * @param bool   $overwrite Replace an existing recording of the same name.
	 * @param bool   $isUpload  True when $source is a $_FILES tmp_name.
	 */
	private function storeWav($name, $source, $overwrite, $isUpload)
	{
		if (!$overwrite && $this->recordingExists($name)) {
			return ['status' => false, 'exists' => true, 'message' => 'A recording called "' . $name . '" already exists.'];
		}

		if (!$this->ensureCustomDir()) {
			return ['status' => false, 'message' => 'Could not create ' . $this->getCustomDir() . '.'];
		}

		$dest = $this->getCustomDir() . '/' . $name . '.' . self::PRIMARY_EXTENSION;

		if ($isUpload) {
			$moved = @move_uploaded_file($source, $dest);
		} else {
			// /tmp is often its own filesystem, where rename() cannot reach
			// across; fall back to a copy in that case.
			$moved = @rename($source, $dest);

			if (!$moved && @copy($source, $dest)) {
				@unlink($source);
				$moved = true;
			}
		}

		if (!$moved) {
			return ['status' => false, 'message' => 'Could not write ' . $dest . '. Check permissions.'];
		}

		@chmod($dest, 0644);

		// Only meaningful when the web process runs as root; on a stock FreePBX
		// PHP is already asterisk and these are no-ops.
		@chown($dest, 'asterisk');
		@chgrp($dest, 'asterisk');

		clearstatcache(true, $dest);

		return [
			'status' => true,
			'name' => $name,
			'path' => $dest,
			'bytes' => (int) @filesize($dest),
			'modified' => (int) @filemtime($dest),
			'message' => 'Saved ' . basename($dest),
		];
	}

	/**
	 * Store an uploaded WAV as <name>.wav in the custom sounds directory.
	 *
	 * @param string $name      Bare name, no extension.
	 * @param bool   $overwrite Replace an existing recording of the same name.
	 * @param array|null $file  A $_FILES entry; defaults to $_FILES['audio'].
	 *
	 * @return array status/message, and on success the stored path.
	 */
	public function saveUpload($name, $overwrite = false, $file = null)
	{
		if (!$this->isValidName($name)) {
			return ['status' => false, 'message' => 'Name must be letters, numbers, dot, dash or underscore -- no spaces.'];
		}

		$file = $file === null ? (isset($_FILES['audio']) ? $_FILES['audio'] : null) : $file;

		if (!is_array($file) || !isset($file['tmp_name'])) {
			return ['status' => false, 'message' => 'No audio was uploaded.'];
		}

		if (!empty($file['error'])) {
			return ['status' => false, 'message' => 'Upload failed (PHP error ' . (int) $file['error'] . '). Check upload_max_filesize and post_max_size.'];
		}

		if (!is_uploaded_file($file['tmp_name'])) {
			return ['status' => false, 'message' => 'Upload was not a real file upload.'];
		}

		if ((int) $file['size'] <= 44) {
			return ['status' => false, 'message' => 'Recording is empty.'];
		}

		if ((int) $file['size'] > self::MAX_UPLOAD_BYTES) {
			return ['status' => false, 'message' => 'Recording is larger than ' . round(self::MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
		}

		// Trust the bytes, not the declared content type: read the RIFF header.
		$header = (string) @file_get_contents($file['tmp_name'], false, null, 0, 12);

		if (strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
			return ['status' => false, 'message' => 'That is not a WAV file.'];
		}

		return $this->storeWav($name, $file['tmp_name'], $overwrite, true);
	}

	/**
	 * Remove a recording and every encoding of it.
	 */
	public function deleteRecording($name, $force = false)
	{
		if (!$this->isValidName($name)) {
			return ['status' => false, 'message' => 'Invalid name.'];
		}

		$usage = $this->getUsage($name);

		if ($usage && !$force) {
			return [
				'status' => false,
				'inUse' => true,
				'usage' => $usage,
				'message' => 'In use by System Recordings: ' . implode(', ', $usage) . '.',
			];
		}

		$dir = $this->getCustomDir();
		$removed = [];

		foreach (self::$audioExtensions as $ext) {
			$path = $dir . '/' . $name . '.' . $ext;

			if (is_file($path) && @unlink($path)) {
				$removed[] = $ext;
			}
		}

		if (!$removed) {
			return ['status' => false, 'message' => 'Nothing was removed. Check permissions on ' . $dir . '.'];
		}

		return ['status' => true, 'name' => $name, 'removed' => $removed, 'message' => 'Deleted ' . $name];
	}

	/**
	 * Send a recording to the browser and stop. Nothing may be echoed before
	 * this, and nothing runs after it.
	 */
	private function streamRecording($name)
	{
		$ext = $this->isValidName($name) ? $this->playableExtension($name) : null;

		if ($ext === null) {
			header('HTTP/1.1 404 Not Found');
			exit;
		}

		$path = $this->getCustomDir() . '/' . $name . '.' . $ext;
		$types = ['wav' => 'audio/wav', 'WAV' => 'audio/wav', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg'];

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: ' . $types[$ext]);
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: inline; filename="' . $name . '.' . $ext . '"');
		header('Cache-Control: private, max-age=0, must-revalidate');
		header('X-Content-Type-Options: nosniff');

		readfile($path);
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Text to speech                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The Piper helper, built on first use.
	 *
	 * __DIR__ is the module directory wherever FreePBX has installed it, which
	 * is the whole point: the runtime and the voices are found relative to it,
	 * never at a path compiled into this file.
	 */
	public function tts()
	{
		if ($this->ttsHelper === null) {
			require_once __DIR__ . '/lib/Tts.php';

			$this->ttsHelper = new \FreePBX\modules\OrykMedia\Tts(__DIR__);
		}

		return $this->ttsHelper;
	}

	/**
	 * Whether Piper is usable here, shaped for whoever is asking.
	 *
	 * The per-check detail names paths on the server, which is exactly what an
	 * administrator needs to fix a broken install and exactly what nobody else
	 * has any business seeing. Non-admins get the one-line reason.
	 */
	public function ttsStatus()
	{
		$status = $this->tts()->status();
		$user = $this->getUser();

		if (empty($user['admin'])) {
			unset($status['checks']);
		}

		return $status;
	}

	/**
	 * Synthesise $text and save it as <name>.wav, exactly as if it had been
	 * recorded from the microphone.
	 *
	 * The name is checked -- and the "already exists" question settled -- before
	 * Piper is started, so a request that was never going to be saved does not
	 * spend a minute of CPU first.
	 */
	public function generateTts($name, $text, $voice, $rate, $overwrite = false)
	{
		if (!$this->isValidName($name)) {
			return ['status' => false, 'message' => 'Name must be letters, numbers, dot, dash or underscore -- no spaces.'];
		}

		if (!$overwrite && $this->recordingExists($name)) {
			return ['status' => false, 'exists' => true, 'message' => 'A recording called "' . $name . '" already exists.'];
		}

		if (!$this->isCustomDirWritable()) {
			return ['status' => false, 'message' => 'Cannot write ' . $this->getCustomDir() . '.'];
		}

		$tts = $this->tts();
		$made = $tts->synthesize($text, $voice, $rate);

		if (empty($made['status'])) {
			return $made;
		}

		try {
			$stored = $this->storeWav($name, $made['path'], $overwrite, false);
		} finally {
			// Whatever happened above, nothing of ours is left in /tmp.
			$tts->cleanup();
		}

		if (!empty($stored['status'])) {
			$stored['seconds'] = $made['seconds'];
			$stored['rate'] = $made['rate'];
			$stored['voice'] = $voice;
			$stored['message'] = 'Generated and saved ' . basename($stored['path']);
		}

		return $stored;
	}

	/* ------------------------------------------------------------------ */
	/* BMO                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * fwconsole ma install / upgrade.
	 *
	 * The only thing this does is fetch the Piper runtime and the default
	 * voice, and it is best effort throughout: Text to Speech is the optional
	 * half of this module, so a box with no outbound network, a firewall in
	 * the way, or simply no interest in TTS still gets a working microphone
	 * recorder. Nothing here is allowed to fail the install.
	 *
	 * It is also a no-op on every install after the first: the script is run
	 * with --if-missing, so an upgrade or a reinstall does not re-download
	 * 115 MB that has not changed since 2023.
	 *
	 * Set ORYK_MEDIA_SKIP_TTS_FETCH=1 to skip it entirely -- for an air-gapped
	 * box, or one where the runtime is delivered by configuration management.
	 */
	public function install()
	{
		$this->installTtsRuntime();
	}

	private function installTtsRuntime()
	{
		$tts = $this->tts();

		if (getenv('ORYK_MEDIA_SKIP_TTS_FETCH')) {
			$this->say('Media: skipping the Piper download (ORYK_MEDIA_SKIP_TTS_FETCH is set).');
			$this->say('Media: run install/fetch-piper.sh later to enable Text to Speech.');

			return;
		}

		if ($tts->runtimeInstalled()) {
			$this->say('Media: the Piper runtime and voice are already installed.');

			return;
		}

		if (!is_file($tts->fetchScript())) {
			$this->say('Media: install/fetch-piper.sh is missing; Text to Speech will stay unavailable.');

			return;
		}

		$this->say('Media: installing the Piper text-to-speech runtime and the default voice (~115 MB).');
		$this->say('Media: this happens once, and can take a few minutes on a slow link.');

		$module = $this;

		$result = $tts->installRuntime(function ($line) use ($module) {
			$module->say('  ' . $line);
		});

		if (empty($result['ok'])) {
			// Deliberately not an exception. A failed download must not leave
			// the module half-installed -- the recorder does not depend on any
			// of this, and the Text to Speech tab explains itself.
			$this->say('Media: could not install Piper -- ' . $result['message']);
			$this->say('Media: recording still works. To retry: ./install/fetch-piper.sh');

			return;
		}

		$status = $tts->status();

		$this->say($status['available']
			? 'Media: Text to Speech is ready.'
			: 'Media: Piper is installed, but Text to Speech is not usable yet -- ' . $status['reason']);
	}

	/**
	 * Say something to whoever is running fwconsole.
	 *
	 * out() is FreePBX's own console writer and is what module installs use;
	 * it is not there when this class is reached from the web, where install()
	 * is not reached either. Public because install() streams a subprocess
	 * through it from a closure.
	 */
	public function say($message)
	{
		if (function_exists('out')) {
			out($message);

			return;
		}

		if (PHP_SAPI === 'cli') {
			echo $message . PHP_EOL;
		}
	}

	/**
	 * The Piper runtime and the voices are left where they are: uninstalling
	 * a module does not remove its directory, and re-installing would only
	 * download them again.
	 */
	public function uninstall()
	{
	}

	/**
	 * What `fwconsole chown` should do with our files.
	 *
	 * It walks a module's directory as type 'rdir', which recursively strips
	 * the execute bit -- and it runs at the end of every install and reload.
	 * Core makes an exception for bin/, hooks/ and agi-bin/, which is why the
	 * Piper runtime lives in bin/piper and is safe without this method at all.
	 *
	 * This says so out loud anyway. It costs nothing, it documents the
	 * requirement where someone moving the directory would see it, and it does
	 * not depend on that core convention staying as it is.
	 *
	 * The voice models are the opposite case: data, read by Piper, never
	 * executed, and large. 0644 under a 0755 directory is exactly right.
	 */
	public function chownFreepbx()
	{
		$files = [];

		$piper = __DIR__ . '/bin/piper';
		$voices = __DIR__ . '/voices';

		if (is_dir($piper)) {
			$files[] = ['type' => 'execdir', 'path' => $piper, 'perms' => 0755];
		}

		if (is_dir($voices)) {
			$files[] = ['type' => 'rdir', 'path' => $voices, 'perms' => 0755];
		}

		return $files;
	}

	public function backup()
	{
	}

	public function restore($backup)
	{
	}

	public function doConfigPageInit($page)
	{
	}

	public function ajaxRequest($req, &$setting)
	{
		$setting['authenticate'] = true;
		$setting['allowremote'] = false;

		return in_array($req, [
			'list', 'save', 'delete', 'play',
			'getTtsStatus', 'getTtsVoices', 'generateTts',
		], true);
	}

	/**
	 * Raw (non-JSON) ajax responses. Returning true tells FreePBX we have
	 * already written the response; play() exits before that matters.
	 */
	public function ajaxCustomHandler()
	{
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';

		if ($command !== 'play') {
			return false;
		}

		$this->streamRecording(isset($_REQUEST['name']) ? $_REQUEST['name'] : '');

		return true;
	}

	public function ajaxHandler()
	{
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
		$name = isset($_REQUEST['name']) ? $_REQUEST['name'] : '';

		switch ($command) {
			case 'list':
				return [
					'status' => true,
					'dir' => $this->getCustomDir(),
					'writable' => $this->isCustomDirWritable(),
					'recordings' => $this->listRecordings(),
				];

			case 'save':
				$overwrite = !empty($_REQUEST['overwrite']) && $_REQUEST['overwrite'] !== 'false';

				$result = $this->saveUpload($name, $overwrite);

				if (!empty($result['status'])) {
					$result['recordings'] = $this->listRecordings();
				}

				return $result;

			case 'delete':
				$force = !empty($_REQUEST['force']) && $_REQUEST['force'] !== 'false';

				$result = $this->deleteRecording($name, $force);

				if (!empty($result['status'])) {
					$result['recordings'] = $this->listRecordings();
				}

				return $result;

			case 'getTtsStatus':
				return array_merge(['status' => true], $this->ttsStatus());

			case 'getTtsVoices':
				return [
					'status' => true,
					'voices' => $this->tts()->availableVoices(),
				];

			case 'generateTts':
				$overwrite = !empty($_REQUEST['overwrite']) && $_REQUEST['overwrite'] !== 'false';

				// Synthesis is CPU-bound and a long text can outlast the default
				// ajax time limit, which would kill PHP mid-Piper and leave the
				// browser with nothing to show for it. tts() first: it is what
				// loads the class the constant lives on.
				$this->tts();
				@set_time_limit(\FreePBX\modules\OrykMedia\Tts::PIPER_TIMEOUT + 60);

				$result = $this->generateTts(
					$name,
					isset($_REQUEST['text']) ? $_REQUEST['text'] : '',
					isset($_REQUEST['voice']) ? $_REQUEST['voice'] : '',
					isset($_REQUEST['rate']) ? $_REQUEST['rate'] : 0,
					$overwrite
				);

				if (!empty($result['status'])) {
					$result['recordings'] = $this->listRecordings();
				}

				return $result;

			case 'play':
				// Only reached if ajaxCustomHandler is not honored on this version.
				$this->streamRecording($name);
				return null;
		}

		return ['status' => false, 'message' => 'Unknown command'];
	}
}
