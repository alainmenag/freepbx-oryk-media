# freepbx-oryk-media

FreePBX module that records audio from the browser microphone and saves it
straight into the Asterisk custom sounds directory, ready to be picked up by
System Recordings.

## What it does

- Captures the mic with Web Audio, encodes **16-bit mono PCM WAV** entirely in
  the browser (8 kHz or 16 kHz), so the server needs no `sox`/`ffmpeg`.
- Writes to `<ASTVARLIBDIR>/sounds/<lang>/custom/<name>.wav` — the same place
  System Recordings reads from, so a saved file appears there as
  `custom/<name>`.
- Lists, previews and deletes what is already in that directory. A delete is
  blocked when a System Recordings entry still points at the file.

## Requirements

- **A secure origin.** Browsers only expose `getUserMedia` over `https://`
  (or `http://localhost`). On a plain-http admin page the module says so and
  the Record button stays disabled.
- The web process must be able to write the custom sounds directory
  (`asterisk:asterisk` on a stock system).

## Layout

```
Oryk_media.class.php   BMO class: paths, listing, upload, delete, ajax
page.oryk_media.php    entry point
views/media.php        recorder UI
assets/js/recorder.js  capture, resample, WAV encode, upload
assets/css/media.css   page-scoped styles
```

## Deploy

`.vscode/sftp.json` points at `/var/www/html/admin/modules/oryk_media`. After
uploading, `fwconsole ma reload && fwconsole reload`.
