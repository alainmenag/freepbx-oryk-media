#!/bin/sh
#
# install/fetch-piper.sh -- put the Piper runtime and a voice where the module
# expects them.
#
# Run it from anywhere; it works on the module directory it lives in, which on
# a FreePBX box is normally /var/www/html/admin/modules/oryk_media.
#
#     ./install/fetch-piper.sh              # runtime + the default voice
#     ./install/fetch-piper.sh --runtime    # runtime only
#     ./install/fetch-piper.sh --voice en_US-lessac-medium
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

MODULE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VENDOR="${MODULE_DIR}/vendor/piper"
VOICE_DIR="${MODULE_DIR}/voices"

WANT_RUNTIME=1
WANT_VOICE="en_US-lessac-medium"

usage() {
	sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'
	exit "${1:-0}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--runtime) WANT_VOICE=""; shift ;;
		--voice) WANT_VOICE="${2:-}"; WANT_RUNTIME=0; shift 2 ;;
		-h|--help) usage 0 ;;
		*) echo "unknown option: $1" >&2; usage 1 ;;
	esac
done

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v curl >/dev/null 2>&1 || die "curl is required"

TMP=$(mktemp -d "${TMPDIR:-/tmp}/oryk-piper.XXXXXX")
trap 'rm -rf "$TMP"' EXIT INT TERM

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------

if [ "$WANT_RUNTIME" -eq 1 ]; then
	say "Fetching Piper ${RELEASE} ..."
	curl -fL --progress-bar -o "${TMP}/piper.tar.gz" "$PIPER_URL" \
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
		say "warning: no sha256 tool; skipping checksum"
	fi

	[ "$GOT" = "$PIPER_SHA256" ] || die "checksum mismatch: got ${GOT}"

	# Piper's RUNPATH is $ORIGIN, so its libraries have to end up in the same
	# directory as the executable. Keep the upstream layout; do not tidy it
	# into bin/ and lib/.
	mkdir -p "$VENDOR"
	cp -a "${TMP}"/x/piper/. "$VENDOR/"
	chmod 755 "${VENDOR}/piper"

	say "Installed runtime in ${VENDOR}"
fi

# ---------------------------------------------------------------------------
# Voice
# ---------------------------------------------------------------------------

if [ -n "$WANT_VOICE" ]; then
	case "$WANT_VOICE" in
		en_US-lessac-medium) VOICE_PATH="en/en_US/lessac/medium" ;;
		*)
			die "unknown voice '${WANT_VOICE}'. Add its huggingface path to this
       script and an entry to lib/voices.php, and check its MODEL_CARD --
       Piper voices are not uniformly licensed."
			;;
	esac

	mkdir -p "$VOICE_DIR"

	say "Fetching voice ${WANT_VOICE} (~63 MB) ..."

	for suffix in ".onnx" ".onnx.json"; do
		curl -fL --progress-bar \
			-o "${VOICE_DIR}/${WANT_VOICE}${suffix}" \
			"${VOICES_BASE}/${VOICE_PATH}/${WANT_VOICE}${suffix}" \
			|| die "could not download ${WANT_VOICE}${suffix}"
	done

	curl -fL -s -o "${MODULE_DIR}/LICENSES/${WANT_VOICE}.MODEL_CARD.txt" \
		"${VOICES_BASE}/${VOICE_PATH}/MODEL_CARD" \
		|| say "warning: could not fetch the MODEL_CARD; record the voice's licence by hand"

	say "Installed voice in ${VOICE_DIR}"
fi

# ---------------------------------------------------------------------------
# Ownership and the things this script cannot install
# ---------------------------------------------------------------------------

if id asterisk >/dev/null 2>&1; then
	chown -R asterisk:asterisk "$VENDOR" "$VOICE_DIR" 2>/dev/null || true
fi

if ! command -v sox >/dev/null 2>&1; then
	say ""
	say "sox is not installed, and the module needs it to convert Piper output."
	say "  dnf install sox      (Rocky / RHEL, with EPEL)"
	say "  apt install sox      (Debian / Ubuntu)"
fi

say ""
say "Done. Reload the Media page; the Text to Speech tab reports what it finds."
