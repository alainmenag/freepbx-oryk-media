<?php

// lib/voices.php
//
// The registry of Piper voices this module is willing to run.
//
// Nothing here is discovered at runtime. A voice exists because it is written
// down in this file, and the paths it names are the only ones the module will
// ever hand to Piper -- the browser picks a key, never a path. Dropping an
// .onnx into voices/ does nothing until it is registered here.
//
// Adding a voice:
//
//   1. Put <voice>.onnx and <voice>.onnx.json in voices/.
//   2. Put its MODEL_CARD in LICENSES/ and check the licence actually permits
//      whatever you intend to do with it. Piper voices are not uniformly
//      licensed -- see LICENSES/README.md.
//   3. Add an entry below.
//
// Keys must match /^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/. Paths are relative to
// the module directory and may not leave it; Tts::registry() drops any entry
// that breaks either rule rather than trusting this file blindly.
//
// Fields:
//   label        what the browser shows.
//   model        .onnx, relative to the module directory.
//   config       .onnx.json, relative to the module directory.
//   sample_rate  what the voice generates natively, for display only -- the
//                real rate is read from the config by Piper itself, and the
//                output is resampled to the rate the user asked for.
//   licence      short human note; the full text lives in LICENSES/.

return [

	'en_US-lessac-medium' => [
		'label' => 'English (US) — Lessac — Medium',
		'model' => 'voices/en_US-lessac-medium.onnx',
		'config' => 'voices/en_US-lessac-medium.onnx.json',
		'sample_rate' => 22050,
		'licence' => 'CC BY 4.0 — see LICENSES/lessac-MODEL_CARD.txt',
	],

];
