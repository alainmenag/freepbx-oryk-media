# voices/

Piper voice models live here, as a matching pair:

```
voices/en_US-lessac-medium.onnx        the model, ~63 MB
voices/en_US-lessac-medium.onnx.json   its config -- sample rate, phoneme map
```

Neither file is kept in git; `install/fetch-piper.sh` downloads them, and
anything else that puts the two files here works just as well.

**A file in this directory is not a voice.** The module never scans this
directory looking for something to run. A voice exists because it is written
down in `lib/voices.php`, and only the paths named there are ever handed to
Piper. Dropping an `.onnx` in here does nothing until it is registered.

## Adding a voice

1. Pick one from [`rhasspy/piper-voices`](https://huggingface.co/rhasspy/piper-voices)
   and read its `MODEL_CARD` **first**. Voices are licensed individually and
   some are not redistributable or not usable commercially — Piper's own MIT
   licence says nothing about them.
2. Download `<voice>.onnx` and `<voice>.onnx.json` into this directory, and put
   its `MODEL_CARD` in `LICENSES/<voice>.MODEL_CARD.txt`.
3. Add an entry to `lib/voices.php`.
4. Note it in the table in `LICENSES/README.md`.

The Text to Speech tab lists whatever is registered *and* present, so a
half-installed voice is simply not offered rather than failing at generation
time.
