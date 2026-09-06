# Third-party components

Everything the Text to Speech feature runs is third-party and is installed
under `vendor/piper/` and `voices/` by `install/fetch-piper.sh`. This file
records exactly what, from where, and under what terms. The module itself is
AGPLv3 (see `module.xml`).

None of this applies to the microphone recorder, which is entirely our own
code and depends on nothing here.

## Piper runtime

| | |
|---|---|
| Project | [`rhasspy/piper`](https://github.com/rhasspy/piper) |
| Release | **`2023.11.14-2`** |
| Asset | `piper_linux_x86_64.tar.gz` |
| Executable sha256 | `12672a94ca6716e5a8f335cfa68bf43bd9a33284960e3f9d16b85090bf7aab6b` |
| Licence | MIT — `piper-MIT.txt` |

This is the **original MIT-licensed Piper**, deliberately pinned.

Piper's current home is [`OHF-Voice/piper1-gpl`](https://github.com/OHF-Voice/piper1-gpl),
which is **GPL-3.0-or-later**. That is a materially different licensing
position from the 2023 series, so the pin is not incidental and
`install/fetch-piper.sh` should not be pointed at the new runtime without
deciding that question deliberately.

### Libraries shipped inside that tarball

The upstream tarball carries no licence files of its own, so they are
reproduced here.

| Library | Project | Licence |
|---|---|---|
| `libpiper_phonemize.so.1` | [rhasspy/piper-phonemize](https://github.com/rhasspy/piper-phonemize) | MIT — `piper-phonemize-MIT.txt` |
| `libonnxruntime.so.1.14.1` | [microsoft/onnxruntime](https://github.com/microsoft/onnxruntime) 1.14.1 | MIT — `onnxruntime-MIT.txt` |
| `libespeak-ng.so.1`, `espeak-ng-data/` | [espeak-ng/espeak-ng](https://github.com/espeak-ng/espeak-ng) 1.52 | **GPL-3.0-or-later** — `espeak-ng-GPL-3.0.txt` |
| `libtashkeel_model.ort` | [mush42/libtashkeel](https://github.com/mush42/libtashkeel) (Arabic diacritics; unused by the bundled voice) | see upstream |

**Read this part before shipping the runtime to anyone.** "The old Piper is
MIT" is true of Piper itself and not of everything in the tarball: eSpeak NG is
GPL-3.0-or-later, and Piper loads it at runtime for phonemisation. Distributing
`vendor/piper/` therefore means distributing GPL-3.0 binaries, which carries the
usual obligation to offer the corresponding source of eSpeak NG to whoever
receives them. The module being AGPLv3 makes that compatible rather than
awkward, but it is an obligation either way, and it does not go away because
the surrounding code is MIT.

If that is unwelcome, the alternative is not to bundle: `install/fetch-piper.sh`
downloads from upstream at install time, and nothing in the PHP cares which way
the files arrived.

## Voices

Voices are **not** covered by Piper's licence. Each one is a separate model
trained on a separate dataset with its own terms, and they vary — some are
CC BY, some are non-commercial, some inherit restrictions from the recordings
they were trained on. There is no blanket answer, so check the MODEL_CARD for
every voice you add before redistributing it or using it commercially.

| Voice | Card | Notes |
|---|---|---|
| `en_US-lessac-medium` | `en_US-lessac-medium.MODEL_CARD.txt` | Lessac / Blizzard Challenge 2013 data. Fetched with the model by `install/fetch-piper.sh`. |

Voice models come from
[`rhasspy/piper-voices`](https://huggingface.co/rhasspy/piper-voices) on
Hugging Face, pinned to tag `v1.0.0`.
