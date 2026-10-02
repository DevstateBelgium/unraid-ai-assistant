#!/bin/bash
# Build the Slackware package and render the plugin definition(s).
#   ./build.sh [VERSION] [--local]
#     VERSION  YYYY.MM.DD or YYYY.MM.DD[a-z] (default: today)
#     --local  also write dist/ai-assistant-claude-local.plg, whose txz <FILE> has no <URL>: it expects the
#              package to be copied to /boot/config/plugins/ai-assistant-claude/ already (offline testing)
# Output:
#   dist/ai-assistant-claude-<ver>-noarch-1.txz (+ .sha256)
#   dist/release-notes.md                       (the CHANGELOG.md entry of <ver>)
#   plugin/ai-assistant-claude.plg              (release plg, txz fetched from the GitHub release)
#   dist/ai-assistant-claude-local.plg          (with --local)
# Environment: SUPPORT_URL overrides the support link (placeholder until the forum thread exists).
#              CI set: a missing CHANGELOG.md entry for VERSION is an error.
set -euo pipefail
shopt -u patsub_replacement 2>/dev/null || true
cd "$(dirname "$(readlink -f "$0")")"
ROOT=$PWD

NAME=ai-assistant-claude
REPO=DevstateBelgium/unraid-ai-assistant
SUPPORT_URL=${SUPPORT_URL:-https://forums.unraid.net/}
SRC=$ROOT/src/usr/local/emhttp/plugins/$NAME
PLUGDIR=usr/local/emhttp/plugins/$NAME

VERSION= LOCAL=0
for a in "$@"; do
  case "$a" in
    --local) LOCAL=1 ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) [ -z "$VERSION" ] && VERSION=$a || { echo "unexpected argument: $a" >&2; exit 2; } ;;
  esac
done
[ -n "$VERSION" ] || VERSION=$(date +%Y.%m.%d)
[[ $VERSION =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "bad version '$VERSION' (want YYYY.MM.DD or YYYY.MM.DD[a-z])" >&2; exit 2; }

TXZ=$NAME-$VERSION-noarch-1.txz
DIST=$ROOT/dist
mkdir -p "$DIST"

# ---- sanity: the tree must at least parse ----
while IFS= read -r -d '' f; do bash -n "$f" || { echo "syntax error in $f" >&2; exit 1; }; done \
  < <(find "$SRC/scripts" "$SRC/event" -type f ! -name '*.php' ! -name 'ua-sanitize' -print0)
while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "php syntax error in $f" >&2; exit 1; }; done \
  < <(find "$SRC" \( -name '*.php' -o -name '*.page' -o -name ua-sanitize \) -print0)

# ---- stage ----
STAGE=$(mktemp -d "${TMPDIR:-/tmp}/aia-build.XXXXXX")
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$(dirname "$PLUGDIR")"
cp -a "$SRC" "$STAGE/$PLUGDIR"
printf '%s\n' "$VERSION" > "$STAGE/$PLUGDIR/VERSION"
# modes: everything 0644/0755 dirs, executables listed explicitly (the git index carries the same bits)
chmod 755 "$STAGE"
find "$STAGE/usr" -type d -exec chmod 755 {} +
find "$STAGE/usr" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/$PLUGDIR"/scripts/* "$STAGE/$PLUGDIR"/hooks/*.php "$STAGE/$PLUGDIR"/mcp/*.php "$STAGE/$PLUGDIR"/event/*
[ "$(id -u)" = 0 ] && chown -R 0:0 "$STAGE"
# reproducible: fixed mtimes (midnight UTC of the version date) so the same tree always gives the same sha256
EPOCH=$(date -u -d "${VERSION:0:4}-${VERSION:5:2}-${VERSION:8:2}" +%s)
find "$STAGE" -exec touch -h -d "@$EPOCH" {} +
find "$STAGE" -name '*.orig' -o -name '*~' -o -name '.DS_Store' | xargs -r rm -f

# ---- package ----
rm -f "$DIST/$TXZ" "$DIST/$TXZ.sha256"
if command -v makepkg >/dev/null 2>&1; then
  mk=$(cd "$STAGE" && makepkg -l y -c n "$DIST/$TXZ" 2>&1) || { echo "$mk" >&2; echo "makepkg failed" >&2; exit 1; }
else
  # No Slackware makepkg (CI, other distros). Reproduce what makepkg does so installpkg/upgradepkg see the
  # same package: member list "./" then relative paths, C-sorted, directories included, root:root numeric,
  # one xz -9 stream with the default CRC64 check. The file list is exactly what makepkg feeds to tar.
  echo "note: makepkg not found, building the package with tar+xz (same layout as makepkg)" >&2
  ( cd "$STAGE" && find ./ | LC_COLLATE=C sort | sed '2,$s,^\./,,' \
      | tar --no-recursion --owner=root --group=root --clamp-mtime --mtime="@$EPOCH" -T - -cf - \
      | xz -9 --threads=2 -c > "$DIST/$TXZ" )
fi
SHA=$(sha256sum "$DIST/$TXZ" | cut -d' ' -f1)
printf '%s  %s\n' "$SHA" "$TXZ" > "$DIST/$TXZ.sha256"

# ---- changelog: CHANGELOG.md ("## YYYY.MM.DD[a-z]" headings) -> plg <CHANGES> and release notes ----
CL=$ROOT/CHANGELOG.md
[ -f "$CL" ] || { echo "CHANGELOG.md not found" >&2; exit 1; }
trim() { sed -e :a -e '/^[[:space:]]*$/{$d;N;ba' -e '}' | sed '/./,$!d'; }
NOTES=$(awk -v v="$VERSION" '/^## /{on=($2==v); next} on' "$CL" | trim)
if [ -z "$NOTES" ]; then
  if [ -n "${CI:-}" ]; then echo "CHANGELOG.md has no '## $VERSION' entry (required for releases)" >&2; exit 1; fi
  echo "warning: CHANGELOG.md has no '## $VERSION' entry, using a placeholder (a release build requires one)" >&2
  NOTES="- Development build."
fi
printf '%s\n' "$NOTES" > "$DIST/release-notes.md"
# plg CHANGES: every entry in file order (newest first), "## X" -> "###X", XML-escaped
CHANGES=$(awk '/^## /{on=1} on' "$CL" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' -e 's/^## */###/' | trim)
grep -qx "###$VERSION" <<<"$CHANGES" || CHANGES=$(printf '###%s\n%s\n\n%s' "$VERSION" "$NOTES" "$CHANGES")

# ---- plg ----
TPL=$(cat "$ROOT/plugin/$NAME.plg.in")
render() {  # render <txz_source_block> <pluginurl_attr>
  local s=$TPL
  s=${s//@VERSION@/$VERSION}
  s=${s//@SHA256@/$SHA}
  s=${s//@CHANGES@/$CHANGES}
  s=${s//@SUPPORT_URL@/$SUPPORT_URL}
  s=${s//@GENERATED@/generated by build.sh from plugin\/$NAME.plg.in, edit the template, not this file}
  s=${s//@TXZ_SOURCE@/$1}
  s=${s//@PLUGINURL_ATTR@/$2}
  printf '%s\n' "$s"
}
URL="https://github.com/$REPO/releases/download/$VERSION/$TXZ"
render "<URL>$URL</URL>" ' pluginURL="&pluginURL;"' > "$ROOT/plugin/$NAME.plg"
if [ $LOCAL = 1 ]; then
  # No <URL>: the plugin manager finds /boot/config/plugins/<name>/<txz> already present, verifies the
  # SHA256 (a mismatching file is deleted!) and skips the download. No pluginURL, so no update checks.
  render "" "" > "$DIST/$NAME-local.plg"
fi

echo "version : $VERSION"
echo "package : $DIST/$TXZ ($(stat -c %s "$DIST/$TXZ") bytes)"
echo "sha256  : $SHA"
echo "plg     : $ROOT/plugin/$NAME.plg"
if [ $LOCAL = 1 ]; then
  echo "local   : $DIST/$NAME-local.plg"
  echo "          offline install on the Unraid host:"
  echo "            mkdir -p /boot/config/plugins/$NAME && cp $DIST/$TXZ /boot/config/plugins/$NAME/"
  echo "            cp $DIST/$NAME-local.plg /tmp/$NAME.plg && plugin install /tmp/$NAME.plg"
  echo "          (keep the file name $NAME.plg: Unraid derives the cron/data folder from it)"
fi
