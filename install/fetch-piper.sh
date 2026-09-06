#!/bin/sh
#
# install/fetch-piper.sh -- put the Piper runtime and a voice where the module
# expects them.
#
# Normally you do not run this by hand: `fwconsole ma install oryk_media`
# calls it through the module's install() hook, with --if-missing. Running it
# yourself is for repairing an install, adding a voice, or installing on a box
# that had no network when the module went on.
#
#     ./install/fetch-piper.sh                 runtime + the default voice
#     ./install/fetch-piper.sh --if-missing    ... but do nothing if both are there
#     ./install/fetch-piper.sh --runtime       runtime only
#     ./install/fetch-piper.sh --voice NAME    one voice only
#
# Neither the runtime nor the voice models are kept in git -- together they are
# well over 100 MB and neither ever changes. This script is how they arrive.
# Nothing in the PHP knows this script exists: it only ever looks for the files
# themselves, so installing them by hand, from a package, or from an internal
# mirror works just as well.
#
# The runtime pinned here is rhasspy/piper 2023.11.14-2, the last of the MIT
# series. It is NOT the current OHF-Voice/piper1-gpl runtime, which is
# GPL-3.0-or-later -- a materially different licensing position. Do not
# "upgrade" this URL without deciding that question first.

set -eu

RELEASE="2023.11.14-2"
ASSET="piper_linux_x86_64.tar.gz"
PIPER_URL="https://github.com/rhasspy/piper/releases/download/${RELEASE}/${ASSET}"

# sha256 of the piper executable inside that asset, so a mirror or a proxy
# swapping the binary out is noticed rather than executed.
PIPER_SHA256="12672a94ca6716e5a8f335cfa68bf43bd9a33284960e3f9d16b85090bf7aab6b"

VOICES_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0"

# Libraries Piper links against. Its RUNPATH is $ORIGIN, so these have to end
# up in the same directory as the executable -- which is also how the module
# decides whether the runtime is really here.
LIBS="libespeak-ng.so.1 libpiper_phonemize.so.1 libonnxruntime.so.1.14.1"

MODULE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VENDOR="${MODULE_DIR}/vendor/piper"
VOICE_DIR="${MODULE_DIR}/voices"

WANT_RUNTIME=1
WANT_VOICE="en_US-lessac-medium"
IF_MISSING=0

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

usage() {
	cat <<'USAGE'
usage: fetch-piper.sh [--if-missing] [--runtime | --voice NAME]

  --if-missing   do nothing when what was asked for is already installed
  --runtime      install the Piper runtime only, no voice
  --voice NAME   install one voice only, no runtime
USAGE
	exit "${1:-0}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--if-missing) IF_MISSING=1; shift ;;
		--runtime) WANT_VOICE=""; shift ;;
		--voice) WANT_VOICE="${2:-}"; WANT_RUNTIME=0; shift 2 ;;
		-h|--help) usage 0 ;;
		*) printf 'unknown option: %s\n' "$1" >&2; usage 1 ;;
	esac
done

# ---------------------------------------------------------------------------
# What is already here
# ---------------------------------------------------------------------------

runtime_installed() {
	[ -x "${VENDOR}/piper" ] || return 1
	[ -d "${VENDOR}/espeak-ng-data" ] || return 1

	for lib in $LIBS; do
		[ -e "${VENDOR}/${lib}" ] || return 1
	done

	return 0
}

voice_installed() {
	[ -s "${VOICE_DIR}/${1}.onnx" ] && [ -s "${VOICE_DIR}/${1}.onnx.json" ]
}

if [ "$IF_MISSING" -eq 1 ]; then
	if [ "$WANT_RUNTIME" -eq 1 ] && runtime_installed; then
		WANT_RUNTIME=0
	fi

	if [ -n "$WANT_VOICE" ] && voice_installed "$WANT_VOICE"; then
		WANT_VOICE=""
	fi

	if [ "$WANT_RUNTIME" -eq 0 ] && [ -z "$WANT_VOICE" ]; then
		say "Piper runtime and voice are already installed; nothing to fetch."
		exit 0
	fi
fi

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

command -v curl >/dev/null 2>&1 || die "curl is required"
command -v tar >/dev/null 2>&1 || die "tar is required"

# A progress bar is worth having at a prompt and is noise in an fwconsole log.
if [ -t 1 ]; then
	CURL="curl -fL --progress-bar --connect-timeout 20 --max-time 900 --retry 2"
else
	CURL="curl -fsSL --connect-timeout 20 --max-time 900 --retry 2"
fi

# Work beside the destination rather than in /tmp: /tmp is a small tmpfs on
# plenty of PBX boxes and this needs ~80 MB of room, and staying on one
# filesystem makes the install a rename rather than a copy across devices.
# The trap takes it away again whichever way this exits.
TMP=$(mktemp -d "${TMPDIR:-${MODULE_DIR}}/oryk-piper.XXXXXX") \
	|| die "could not create a temporary directory"
trap 'rm -rf "$TMP"' EXIT INT TERM

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------

if [ "$WANT_RUNTIME" -eq 1 ]; then
	say "Fetching the Piper runtime, release ${RELEASE} (~26 MB) ..."

	$CURL -o "${TMP}/piper.tar.gz" "$PIPER_URL" \
		|| die "could not download ${PIPER_URL}"

	mkdir -p "${TMP}/x"
	tar xzf "${TMP}/piper.tar.gz" -C "${TMP}/x"

	[ -f "${TMP}/x/piper/piper" ] || die "unexpected archive layout"

	if command -v sha256sum >/dev/null 2>&1; then
		GOT=$(sha256sum "${TMP}/x/piper/piper" | cut -d' ' -f1)
	elif command -v shasum >/dev/null 2>&1; then
		GOT=$(shasum -a 256 "${TMP}/x/piper/piper" | cut -d' ' -f1)
	else
		GOT="$PIPER_SHA256"
		say "warning: no sha256 tool available; skipping the checksum"
	fi

	[ "$GOT" = "$PIPER_SHA256" ] || die "checksum mismatch on the piper executable: got ${GOT}"

	# Keep the upstream layout. Piper's RUNPATH is $ORIGIN, so its libraries
	# have to stay in the same directory as the executable -- do not tidy this
	# into bin/ and lib/.
	mkdir -p "$VENDOR"
	cp -a "${TMP}"/x/piper/. "${VENDOR}/"
	chmod 755 "${VENDOR}/piper"

	say "Installed the runtime in ${VENDOR}"
fi

# ---------------------------------------------------------------------------
# Voice
# ---------------------------------------------------------------------------

if [ -n "$WANT_VOICE" ]; then
	case "$WANT_VOICE" in
		en_US-lessac-medium) VOICE_PATH="en/en_US/lessac/medium" ;;
		*)
			die "unknown voice '${WANT_VOICE}'. Add its huggingface path to this
       script and an entry to lib/voices.php, and read its MODEL_CARD --
       Piper voices are licensed individually."
			;;
	esac

	mkdir -p "$VOICE_DIR" "${MODULE_DIR}/LICENSES"

	say "Fetching the voice ${WANT_VOICE} (~63 MB) ..."

	# Download beside the destination and move into place at the end, so an
	# interrupted fetch cannot leave a half-written model that looks installed.
	for suffix in ".onnx" ".onnx.json"; do
		$CURL -o "${TMP}/voice${suffix}" \
			"${VOICES_BASE}/${VOICE_PATH}/${WANT_VOICE}${suffix}" \
			|| die "could not download ${WANT_VOICE}${suffix}"
	done

	[ -s "${TMP}/voice.onnx" ] || die "the downloaded model is empty"

	mv "${TMP}/voice.onnx" "${VOICE_DIR}/${WANT_VOICE}.onnx"
	mv "${TMP}/voice.onnx.json" "${VOICE_DIR}/${WANT_VOICE}.onnx.json"

	$CURL -o "${MODULE_DIR}/LICENSES/${WANT_VOICE}.MODEL_CARD.txt" \
		"${VOICES_BASE}/${VOICE_PATH}/MODEL_CARD" \
		|| say "warning: could not fetch the MODEL_CARD; record this voice's licence by hand"

	say "Installed the voice in ${VOICE_DIR}"
fi

# ---------------------------------------------------------------------------
# Ownership, and the thing this script cannot install
# ---------------------------------------------------------------------------

if id asterisk >/dev/null 2>&1; then
	chown -R asterisk:asterisk "$VENDOR" "$VOICE_DIR" 2>/dev/null || true
fi

if ! command -v sox >/dev/null 2>&1; then
	say ""
	say "sox is not installed, and Text to Speech needs it to convert Piper's"
	say "output. Install it and the feature turns itself on:"
	say "  dnf install sox      (Rocky / RHEL, with EPEL)"
	say "  apt install sox      (Debian / Ubuntu)"
fi

say "Done."
