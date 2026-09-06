# freepbx-oryk-media

FreePBX module for making audio without leaving the admin UI. Record it from
the browser microphone, or type it and have the server speak it, and either way
it lands straight in the Asterisk custom sounds directory ready to be picked up
by System Recordings.

## What it does

Two ways to create a recording, one place they end up.

**Record from the microphone.** Captures the mic with Web Audio and encodes
**16-bit mono PCM WAV** entirely in the browser (8 kHz or 16 kHz). Asterisk
plays that format as-is, so this path needs no server-side audio tooling at
all — no `sox`, no `ffmpeg`, nothing beyond PHP.

**Text to Speech.** Types in text, picks a voice, and gets a recording back.
Synthesis runs locally with a bundled [Piper](https://github.com/rhasspy/piper)
runtime: the text goes to this server and nowhere else, no API key, no account,
no network call. This half *is* optional — if the Piper runtime or `sox` is
missing the tab says so and the recorder carries on working. The default voice
is `en_US-lessac-medium`.

Whichever way it was made, the file is the same afterwards. It is written to
`<ASTVARLIBDIR>/sounds/<lang>/custom/<name>.wav`, shows up in System Recordings
as `custom/<name>`, appears in the same list here, previews with the same
player, and a delete is blocked the same way when a System Recordings entry
still points at it.

## Requirements

- **A secure origin**, for the microphone. Browsers only expose `getUserMedia`
  over `https://` (or `http://localhost`). On a plain-http admin page the module
  says so and the Record button stays disabled. Text to Speech is unaffected.
- The web process must be able to write the custom sounds directory
  (`asterisk:asterisk` on a stock system).
- For Text to Speech only: the bundled Piper runtime (see below) and **`sox`**,
  which converts Piper's 22.05 kHz output to the rate Asterisk wants.
  `dnf install sox` on Rocky/RHEL with EPEL, `apt install sox` on Debian.

## Installing the Piper runtime

The runtime (~52 MB) and the voice models (~63 MB each) are not kept in git.
**Installing the module fetches them:**

```sh
fwconsole ma install oryk_media
```

That downloads rhasspy/piper `2023.11.14-2` into `vendor/piper/` and
`en_US-lessac-medium` into `voices/`, verifies the executable's checksum, and
sets ownership, printing progress as it goes. Everything resolves relative to
the module directory — there is no `/opt/tts` and no path compiled into the
PHP — so the module stays portable and behaves the same way on the next box.

It runs **once**. Later installs and upgrades find the files already there and
skip the download entirely.

Nothing about this is load-bearing. If the box has no route out, or the
download fails, or you set `ORYK_MEDIA_SKIP_TTS_FETCH=1`, the install still
succeeds and the microphone recorder is unaffected — only the Text to Speech
tab reports itself unavailable. To do it later, or to repair it:

```sh
cd /var/www/html/admin/modules/oryk_media
./install/fetch-piper.sh
```

The PHP knows nothing about that script; it only ever looks for the files. So
delivering them by package, internal mirror or `scp` works just as well.

The Text to Speech tab reports what it found. When something is missing an
administrator sees exactly which check failed and where it looked; everyone
else just sees that the feature is unavailable.

Piper's libraries have to sit in the same directory as the executable — its
`RUNPATH` is `$ORIGIN` — so keep the upstream layout rather than tidying it
into `bin/` and `lib/`.

## Adding another voice

1. Read the voice's `MODEL_CARD` first. Piper voices are licensed
   individually and some are not redistributable — see `LICENSES/README.md`.
2. Put `<voice>.onnx` and `<voice>.onnx.json` in `voices/`.
3. Register it in `lib/voices.php`:

```php
'en_GB-alba-medium' => [
    'label'       => 'English (GB) — Alba — Medium',
    'model'       => 'voices/en_GB-alba-medium.onnx',
    'config'      => 'voices/en_GB-alba-medium.onnx.json',
    'sample_rate' => 22050,
    'licence'     => 'see LICENSES/en_GB-alba-medium.MODEL_CARD.txt',
],
```

4. Reload the page.

The registry is the whole allowlist: the browser sends a voice *key*, the
server looks it up, and nothing else is ever reachable. A model dropped into
`voices/` without an entry here is ignored — the module does not scan the
directory and run whatever it finds.

## Layout

```
Oryk_media.class.php     BMO class: paths, listing, upload, delete, ajax
page.oryk_media.php      entry point
lib/
  Tts.php                Piper: readiness checks, validation, execution
  voices.php             the voice registry -- the allowlist
views/
  media.php              both creation methods, plus the saved list
assets/
  js/recorder.js         mic capture, resample, WAV encode, upload
  js/tts.js              the Text to Speech panel and the tab switch
  css/media.css          page-scoped styles
install/
  fetch-piper.sh         installs the runtime and voices
vendor/piper/            Piper executable, its libraries, espeak-ng-data  (not in git)
voices/                  *.onnx and *.onnx.json                          (not in git)
LICENSES/                licences and provenance for everything bundled
```

## How Text to Speech runs

Worth knowing if you are reviewing it: the browser sends text, a voice key and
a sample rate, and never a path, a filename on disk, or anything resembling a
command line. Server-side, the name is checked against the same
`[A-Za-z0-9._-]` rule as a recording, the voice key is looked up in the
registry, the text is capped at 5,000 characters and flattened to one line, and
Piper is started through `proc_open()` with the argument vector as an **array**
— there is no shell in the picture, so quoting and metacharacters never arise.
The text goes down stdin rather than the command line. Output is written to a
private temp directory that is swept on success, on failure and on shutdown.

An existing recording is never replaced without a second, explicit click, the
same as the recorder.

## Deploy

`.vscode/sftp.json` points at `/var/www/html/admin/modules/oryk_media`. After
uploading, `fwconsole ma reload && fwconsole reload`.
