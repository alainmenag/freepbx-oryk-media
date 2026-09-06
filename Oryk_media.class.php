<?php

// Oryk_media.class.php

namespace FreePBX\modules;

use PDO;
use FreePBX_Helpers;

/**
 * Media -- record audio in the browser and drop it where FreePBX looks for
 * custom sounds, so it can be picked up by System Recordings.
 *
 * The browser does all the encoding: recorder.js captures from the mic,
 * resamples with an OfflineAudioContext and writes a 16-bit mono PCM WAV. That
 * is a format Asterisk plays natively, so nothing here shells out to sox or
 * ffmpeg and the module has no runtime dependency beyond PHP.
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

	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman;
	}

	public function showPage()
	{
		$page = isset($_REQUEST['display']) ? $_REQUEST['display'] : 'default';

		switch ($page) {
			case 'oryk_media':
				return load_view(__DIR__ . '/views/media.php', [
					'user' => $this->getUser(),
					'customDir' => $this->getCustomDir(),
					'writable' => $this->isCustomDirWritable(),
					'maxBytes' => self::MAX_UPLOAD_BYTES,
					'recordings' => $this->listRecordings(),
					'assetUrl' => [$this, 'assetUrl'],
				]);
			default:
				break;
		}
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

		if (!$overwrite && $this->recordingExists($name)) {
			return ['status' => false, 'exists' => true, 'message' => 'A recording called "' . $name . '" already exists.'];
		}

		if (!$this->ensureCustomDir()) {
			return ['status' => false, 'message' => 'Could not create ' . $this->getCustomDir() . '.'];
		}

		$dest = $this->getCustomDir() . '/' . $name . '.' . self::PRIMARY_EXTENSION;

		if (!@move_uploaded_file($file['tmp_name'], $dest)) {
			return ['status' => false, 'message' => 'Could not write ' . $dest . '. Check permissions.'];
		}

		@chmod($dest, 0644);

		// Only meaningful when the web server runs as root; on a stock FreePBX
		// PHP is already asterisk and these are no-ops.
		@chown($dest, 'asterisk');
		@chgrp($dest, 'asterisk');

		return [
			'status' => true,
			'name' => $name,
			'path' => $dest,
			'bytes' => (int) @filesize($dest),
			'message' => 'Saved ' . basename($dest),
		];
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
	/* BMO                                                                 */
	/* ------------------------------------------------------------------ */

	public function install()
	{
	}

	public function uninstall()
	{
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

		return in_array($req, ['list', 'save', 'delete', 'play'], true);
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

			case 'play':
				// Only reached if ajaxCustomHandler is not honored on this version.
				$this->streamRecording($name);
				return null;
		}

		return ['status' => false, 'message' => 'Unknown command'];
	}
}
