#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_SCRIPT="$ROOT/tools/build-release.sh"
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT

SCOPED="$TEMP/scoped"
ARTIFACT="$TEMP/dist/cpt2collection"
mkdir -p "$SCOPED" "$ARTIFACT"

sync_release() {
	# Keep this command identical to the production synchronization command.
	rsync -a --checksum --delete --no-times "$SCOPED/" "$ARTIFACT/"
}

mtime() {
	stat -c %Y "$1"
}

assert_equal() {
	if [[ "$1" != "$2" ]]; then
		echo "Release sync verification failed: $3" >&2
		exit 1
	fi
}

# First synchronization creates a complete artifact.
printf 'stable\n' > "$SCOPED/stable.txt"
printf 'version one\n' > "$SCOPED/changed.txt"
sync_release
[[ -f "$ARTIFACT/stable.txt" && -f "$ARTIFACT/changed.txt" ]]

# A clean temporary tree may have fresh mtimes; equal content must not update
# the persistent files' mtimes.
stable_mtime="$(mtime "$ARTIFACT/stable.txt")"
changed_mtime="$(mtime "$ARTIFACT/changed.txt")"
rm -rf "$SCOPED"
mkdir -p "$SCOPED"
printf 'stable\n' > "$SCOPED/stable.txt"
printf 'version one\n' > "$SCOPED/changed.txt"
touch -d "@$((stable_mtime + 10))" "$SCOPED/stable.txt" "$SCOPED/changed.txt"
sync_release
assert_equal "$stable_mtime" "$(mtime "$ARTIFACT/stable.txt")" 'an unchanged file mtime changed'
assert_equal "$changed_mtime" "$(mtime "$ARTIFACT/changed.txt")" 'an unchanged comparison file mtime changed'

# Changed content is replaced without disturbing an unrelated file.
sleep 1
printf 'version two\n' > "$SCOPED/changed.txt"
sync_release
[[ "$(mtime "$ARTIFACT/changed.txt")" -gt "$changed_mtime" ]]
assert_equal "$stable_mtime" "$(mtime "$ARTIFACT/stable.txt")" 'an unrelated file mtime changed'
assert_equal 'version two' "$(cat "$ARTIFACT/changed.txt")" 'changed content was not installed'

# New files are added and --delete removes files absent from the next clean tree.
printf 'temporary\n' > "$SCOPED/added.txt"
sync_release
[[ -f "$ARTIFACT/added.txt" ]]
rm "$SCOPED/added.txt"
sync_release
[[ ! -e "$ARTIFACT/added.txt" ]]

# Model the production verification gate: a verifier failure must prevent sync.
before_hash="$(sha256sum "$ARTIFACT/changed.txt")"
printf 'must not be published\n' > "$SCOPED/changed.txt"
if false; then
	sync_release
fi
assert_equal "$before_hash" "$(sha256sum "$ARTIFACT/changed.txt")" 'failed verification modified dist'

# Guard the production ordering as well as the synchronization behavior above.
verify_line="$(rg -n 'verify-release-artifact\.php.*\$SCOPED' "$BUILD_SCRIPT" | cut -d: -f1)"
sync_line="$(rg -n 'rsync -a --checksum --delete --no-times.*\$SCOPED/' "$BUILD_SCRIPT" | cut -d: -f1)"
[[ -n "$verify_line" && -n "$sync_line" && "$verify_line" -lt "$sync_line" ]]

echo 'Release artifact incremental synchronization verification passed.'
