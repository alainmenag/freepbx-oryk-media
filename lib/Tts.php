<?php

// lib/Tts.php

namespace FreePBX\modules\OrykMedia;

/**
 * Piper text-to-speech, run locally.
 *
 * Everything this class touches resolves from the module directory it is
 * handed at construction: the Piper binary, its shared libraries, its
 * espeak-ng data, and the voice models. Nothing is read from /opt, nothing is
 * looked up in the environment, and no path ever comes from the browser -- a
 * request names a voice *key*, which is looked up in the registry.
 *
 * Piper is invoked through proc_open() with the command as an array, so there
 * is no shell to inject into: the argv vector is passed to execvp() as-is and
 * the text goes down stdin rather than the command line. escapeshellarg() is
 * only reached on PHP older than 7.4, which FreePBX 16 does not ship.
 *
 * Piper writes 22.05 kHz mono; sox converts that to the 8 kHz (or 16 kHz)
 * 16-bit mono WAV Asterisk wants. Only the conversion step needs sox -- the
 * microphone recorder still does its own encoding in the browser and has no
 * server-side dependency at all.
 */
class Tts
{
	/** Longest text we will synthesise in one go. */
	const MAX_TEXT_CHARS = 5000;

	/** Output rates offered, and the one Asterisk is happiest with. */
	const OUTPUT_RATES = [8000, 16000];
	const DEFAULT_RATE = 8000;

	/** Wall-clock ceiling on each child process, in seconds. */
	const PIPER_TIMEOUT = 180;
	const SOX_TIMEOUT = 60;

	/** A voice key is a registry lookup, not a path. Shaped like one anyway. */
	const VOICE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	/** Where sox may live. Checked in order; $PATH is consulted after these. */
	private static $soxCandidates = [
		'/usr/bin/sox',
		'/usr/local/bin/sox',
		'/bin/sox',
		'/usr/sbin/sox',
	];

	/** @var string Absolute path to the module directory. No trailing slash. */
	private $moduleDir;

	/** @var array|null Validated registry, loaded once per request. */
	private $registry = null;

	/** @var string|null|false Resolved sox path; false until looked for. */
	private $sox = false;

	/** @var string[] Temp directories to remove before the request ends. */
	private $temps = [];

	/** @var bool Whether the shutdown sweeper is registered. */
	private $sweeping = false;

	public function __construct($moduleDir)
	{
		$this->moduleDir = rtrim($moduleDir, '/');
	}

	/* ------------------------------------------------------------------ */
	/* Where the runtime lives                                             */
	/* ------------------------------------------------------------------ */

	public function piperDir()
	{
		return $this->moduleDir . '/vendor/piper';
	}

	public function piperBinary()
	{
		return $this->piperDir() . '/piper';
	}

	public function espeakData()
	{
		return $this->piperDir() . '/espeak-ng-data';
	}

	public function soxBinary()
	{
		if ($this->sox !== false) {
			return $this->sox;
		}

		$this->sox = null;

		$candidates = self::$soxCandidates;

		// $PATH is consulted for a fixed literal name -- nothing here is
		// attacker-controlled, and a caller can still only reach sox.
		foreach (explode(':', (string) getenv('PATH')) as $dir) {
			if ($dir !== '' && $dir[0] === '/') {
				$candidates[] = rtrim($dir, '/') . '/sox';
			}
		}

		foreach ($candidates as $path) {
			if (is_file($path) && is_executable($path)) {
				return $this->sox = $path;
			}
		}

		return $this->sox;
	}

	/* ------------------------------------------------------------------ */
	/* Voice registry                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Registered voices, keyed by voice id, with absolute paths resolved.
	 *
	 * lib/voices.php is authored by an administrator, but it is still checked
	 * here: a key has to look like a key, and both files have to sit inside the
	 * module directory. An entry that fails is dropped, not repaired.
	 *
	 * Entries are returned whether or not the model is present on disk --
	 * `installed` says which. The UI only ever offers the installed ones.
	 */
	public function registry()
	{
		if ($this->registry !== null) {
			return $this->registry;
		}

		$this->registry = [];

		$file = $this->moduleDir . '/lib/voices.php';

		if (!is_file($file)) {
			return $this->registry;
		}

		$declared = @include $file;

		if (!is_array($declared)) {
			$this->log('voices.php did not return an array');

			return $this->registry;
		}

		foreach ($declared as $id => $voice) {
			if (!is_string($id) || !preg_match(self::VOICE_PATTERN, $id) || !is_array($voice)) {
				$this->log('ignoring malformed voice registry entry');
				continue;
			}

			$model = $this->insideModule(isset($voice['model']) ? $voice['model'] : '');
			$config = $this->insideModule(isset($voice['config']) ? $voice['config'] : '');

			if ($model === null || $config === null) {
				$this->log('ignoring voice "' . $id . '": model or config path escapes the module directory');
				continue;
			}

			$this->registry[$id] = [
				'id' => $id,
				'label' => isset($voice['label']) ? (string) $voice['label'] : $id,
				'model' => $model,
				'config' => $config,
				'sample_rate' => isset($voice['sample_rate']) ? (int) $voice['sample_rate'] : 0,
				'licence' => isset($voice['licence']) ? (string) $voice['licence'] : '',
				'installed' => is_file($model) && is_readable($model)
					&& is_file($config) && is_readable($config),
			];
		}

		return $this->registry;
	}

	/**
	 * Absolute path for a module-relative path, or null if it would leave the
	 * module directory. Resolves the parent rather than the file itself, so a
	 * voice that is registered but not yet installed still validates.
	 *
	 * A symlink under voices/ is followed on purpose: pointing it at a shared
	 * model directory elsewhere on the box is a reasonable way to deploy, and
	 * creating one already requires the filesystem access that editing this
	 * registry does. What is blocked here is the registry *text* naming
	 * somewhere else -- '..', or an absolute path.
	 */
	private function insideModule($relative)
	{
		if (!is_string($relative) || $relative === '' || strpos($relative, "\0") !== false) {
			return null;
		}

		if ($relative[0] === '/' || strpos($relative, '..') !== false) {
			return null;
		}

		$path = $this->moduleDir . '/' . ltrim($relative, '/');
		$parent = realpath(dirname($path));
		$root = realpath($this->moduleDir);

		if ($parent === false || $root === false) {
			return null;
		}

		if ($parent !== $root && strpos($parent, $root . '/') !== 0) {
			return null;
		}

		return $parent . '/' . basename($path);
	}

	/**
	 * The voices a browser may choose from: registered *and* on disk.
	 */
	public function availableVoices()
	{
		$out = [];

		foreach ($this->registry() as $id => $voice) {
			if (!$voice['installed']) {
				continue;
			}

			$out[] = [
				'id' => $id,
				'label' => $voice['label'],
				'sample_rate' => $voice['sample_rate'],
			];
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Readiness                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether Piper can be used, and if not, why.
	 *
	 * `checks` carries paths and is meant for an administrator; the caller
	 * decides who sees it. `reason` is the one-line version everybody gets.
	 */
	public function status()
	{
		$binary = $this->piperBinary();
		$espeak = $this->espeakData();
		$sox = $this->soxBinary();
		$voices = $this->availableVoices();

		$checks = [
			[
				'label' => 'Piper executable',
				'ok' => is_file($binary) && is_executable($binary),
				'detail' => is_file($binary)
					? (is_executable($binary) ? $binary : $binary . ' is not executable (chmod 755)')
					: $binary . ' is missing',
			],
			[
				'label' => 'Piper shared libraries',
				'ok' => $this->librariesPresent(),
				'detail' => $this->librariesPresent()
					? $this->piperDir()
					: 'libespeak-ng / libpiper_phonemize / libonnxruntime are not in ' . $this->piperDir(),
			],
			[
				'label' => 'espeak-ng data',
				'ok' => is_dir($espeak),
				'detail' => is_dir($espeak) ? $espeak : $espeak . ' is missing',
			],
			[
				'label' => 'sox',
				'ok' => $sox !== null,
				'detail' => $sox !== null ? $sox : 'not found in /usr/bin, /usr/local/bin or $PATH',
			],
			[
				'label' => 'Installed voices',
				'ok' => !empty($voices),
				'detail' => empty($voices)
					? 'no registered voice has both its .onnx and .onnx.json in ' . $this->moduleDir . '/voices'
					: count($voices) . ' of ' . count($this->registry()) . ' registered',
			],
		];

		$failed = [];

		foreach ($checks as $check) {
			if (!$check['ok']) {
				$failed[] = $check['label'];
			}
		}

		return [
			'available' => empty($failed),
			'reason' => empty($failed) ? '' : 'Missing: ' . implode(', ', $failed) . '.',
			'checks' => $checks,
			'voices' => $voices,
			'rates' => self::OUTPUT_RATES,
			'defaultRate' => self::DEFAULT_RATE,
			'maxChars' => self::MAX_TEXT_CHARS,
		];
	}

	/**
	 * Piper's RUNPATH is $ORIGIN, so its libraries have to sit beside it.
	 * Check the sonames it actually links against rather than the whole set.
	 */
	private function librariesPresent()
	{
		foreach (['libespeak-ng.so.1', 'libpiper_phonemize.so.1', 'libonnxruntime.so.1.14.1'] as $lib) {
			if (!file_exists($this->piperDir() . '/' . $lib)) {
				return false;
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Input validation                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Text as Piper will receive it, or null if it is not usable.
	 *
	 * Collapsed to a single line on purpose: Piper treats every input line as
	 * a separate utterance and writes each one to --output_file in turn, so a
	 * multi-line request would leave only the last line's audio behind. One
	 * line is one WAV. Piper still splits it into sentences internally, so
	 * punctuation and phrasing survive.
	 */
	public function normaliseText($text)
	{
		if (!is_string($text)) {
			return null;
		}

		if (!mb_check_encoding($text, 'UTF-8')) {
			return null;
		}

		// Control characters, including the NULs and escapes that make a mess
		// of a pipe. Newlines and tabs become spaces rather than vanishing.
		$text = preg_replace('/[\r\n\t]+/u', ' ', $text);
		$text = preg_replace('/[\x00-\x1F\x7F]+/u', '', $text);
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = trim((string) $text);

		if ($text === '') {
			return null;
		}

		if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARS) {
			return null;
		}

		return $text;
	}

	public function isValidRate($rate)
	{
		return in_array((int) $rate, self::OUTPUT_RATES, true);
	}

	/* ------------------------------------------------------------------ */
	/* Synthesis                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Speak $text in $voiceId and hand back a temporary Asterisk-ready WAV.
	 *
	 * The file lives in a temp directory owned by this object; the caller is
	 * expected to move it somewhere permanent and then call cleanup(). A
	 * shutdown hook sweeps it either way, so an exception or a fatal on the
	 * way out does not leave audio in /tmp.
	 *
	 * @return array status/message, and on success `path`, `seconds`, `bytes`.
	 */
	public function synthesize($text, $voiceId, $rate)
	{
		$status = $this->status();

		if (!$status['available']) {
			return ['status' => false, 'message' => 'Text to Speech is unavailable on this system.'];
		}

		$line = $this->normaliseText($text);

		if ($line === null) {
			return [
				'status' => false,
				'message' => 'Enter some text -- up to ' . number_format(self::MAX_TEXT_CHARS) . ' characters.',
			];
		}

		$registry = $this->registry();

		if (!is_string($voiceId) || !isset($registry[$voiceId]) || !$registry[$voiceId]['installed']) {
			return ['status' => false, 'message' => 'That voice is not available.'];
		}

		$voice = $registry[$voiceId];
		$rate = $this->isValidRate($rate) ? (int) $rate : self::DEFAULT_RATE;

		$dir = $this->makeTempDir();

		if ($dir === null) {
			return ['status' => false, 'message' => 'Could not create a temporary directory.'];
		}

		$raw = $dir . '/piper.wav';
		$final = $dir . '/final.wav';

		$piper = $this->run([
			$this->piperBinary(),
			'--model', $voice['model'],
			'--config', $voice['config'],
			'--espeak_data', $this->espeakData(),
			'--output_file', $raw,
			'--quiet',
		], $line, self::PIPER_TIMEOUT, $dir);

		if (!$piper['ok'] || !is_file($raw) || filesize($raw) <= 44) {
			$this->log('piper failed (exit ' . $piper['code'] . '): ' . $piper['stderr']);
			$this->cleanup();

			return [
				'status' => false,
				'message' => $piper['timedout']
					? 'Speech generation timed out. Try shorter text.'
					: 'Speech generation failed. Check the FreePBX log for details.',
			];
		}

		// sox -r/-c/-b describe the *output* file, which is what we want: one
		// pass from whatever Piper produced to signed 16-bit mono PCM.
		$sox = $this->run([
			$this->soxBinary(),
			$raw,
			'-r', (string) $rate,
			'-c', '1',
			'-b', '16',
			'-e', 'signed-integer',
			$final,
		], null, self::SOX_TIMEOUT, $dir);

		if (!$sox['ok'] || !is_file($final) || filesize($final) <= 44) {
			$this->log('sox failed (exit ' . $sox['code'] . '): ' . $sox['stderr']);
			$this->cleanup();

			return ['status' => false, 'message' => 'Could not convert the generated audio.'];
		}

		$bytes = (int) filesize($final);

		return [
			'status' => true,
			'path' => $final,
			'bytes' => $bytes,
			'rate' => $rate,
			'seconds' => round(max(0, $bytes - 44) / ($rate * 2), 2),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Processes and temp files                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Run a command with no shell involved.
	 *
	 * proc_open() takes the argv vector as an array from PHP 7.4 on, which is
	 * what FreePBX 16 and 17 both ship; the arguments reach execvp() untouched
	 * so quoting and metacharacters simply do not arise. The escapeshellarg()
	 * branch exists for older builds and is never the primary path.
	 *
	 * The environment is replaced rather than inherited: Piper's RUNPATH is
	 * $ORIGIN, but LD_LIBRARY_PATH is set as well so a relocated library
	 * directory still resolves, and the locale is pinned because espeak-ng
	 * cares about it.
	 */
	private function run(array $command, $stdin, $timeout, $cwd)
	{
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$env = [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'LD_LIBRARY_PATH' => $this->piperDir(),
			'HOME' => $cwd,
			'TMPDIR' => $cwd,
			'LANG' => 'C.UTF-8',
			'LC_ALL' => 'C.UTF-8',
		];

		$spec = PHP_VERSION_ID >= 70400
			? $command
			: implode(' ', array_map('escapeshellarg', $command));

		$pipes = [];
		$process = @proc_open($spec, $descriptors, $pipes, $cwd, $env);

		if (!is_resource($process)) {
			return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => 'could not start ' . basename($command[0]), 'timedout' => false];
		}

		// Input is capped at a few kB, comfortably inside the pipe buffer, so
		// writing it in one go cannot deadlock against a child that has not
		// started reading yet.
		if ($stdin !== null && $stdin !== '') {
			@fwrite($pipes[0], $stdin . "\n");
		}

		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$out = ['', ''];
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		$deadline = microtime(true) + $timeout;
		$timedout = false;

		while ($open) {
			$remaining = $deadline - microtime(true);

			if ($remaining <= 0) {
				$timedout = true;
				break;
			}

			$read = array_values($open);
			$write = $except = [];

			$ready = @stream_select($read, $write, $except, (int) $remaining, 200000);

			if ($ready === false) {
				break;
			}

			foreach ($open as $key => $pipe) {
				if (!in_array($pipe, $read, true)) {
					continue;
				}

				$chunk = fread($pipe, 8192);

				if ($chunk === '' || $chunk === false) {
					if (feof($pipe)) {
						fclose($pipe);
						unset($open[$key]);
					}

					continue;
				}

				// Diagnostics only; keep a runaway child from eating memory.
				if (strlen($out[$key - 1]) < 65536) {
					$out[$key - 1] .= $chunk;
				}
			}
		}

		foreach ($open as $pipe) {
			fclose($pipe);
		}

		if ($timedout) {
			proc_terminate($process, 9);
		}

		$code = proc_close($process);

		return [
			'ok' => !$timedout && $code === 0,
			'code' => (int) $code,
			'stdout' => $out[0],
			'stderr' => trim($out[1]),
			'timedout' => $timedout,
		];
	}

	/**
	 * A private directory under the system temp dir, mode 0700, with a name
	 * nothing else can predict.
	 */
	private function makeTempDir()
	{
		$base = rtrim(sys_get_temp_dir(), '/');

		for ($attempt = 0; $attempt < 5; $attempt++) {
			$dir = $base . '/oryk_media_tts_' . bin2hex(random_bytes(8));

			if (@mkdir($dir, 0700)) {
				$this->temps[] = $dir;
				$this->sweepOnShutdown();

				return $dir;
			}
		}

		$this->log('could not create a temp directory under ' . $base);

		return null;
	}

	/**
	 * Remove every temp directory this object made. Safe to call twice, and
	 * called both on the success path and on every failure path.
	 */
	public function cleanup()
	{
		foreach ($this->temps as $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			foreach ((array) @scandir($dir) as $entry) {
				if ($entry !== '.' && $entry !== '..') {
					@unlink($dir . '/' . $entry);
				}
			}

			@rmdir($dir);
		}

		$this->temps = [];
	}

	private function sweepOnShutdown()
	{
		if ($this->sweeping) {
			return;
		}

		$this->sweeping = true;

		register_shutdown_function([$this, 'cleanup']);
	}

	/**
	 * Server-side only. Failures are an administrator's problem, not the
	 * browser's -- the browser gets a sentence, the log gets the detail.
	 */
	private function log($message)
	{
		if (function_exists('freepbx_log')) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_media tts: ' . $message);

			return;
		}

		error_log('oryk_media tts: ' . $message);
	}
}
