#!/bin/bash
# cPanel deploy step (called from .cpanel.yml): publish the built site into the web root AND remove files that no longer exist in the build.
# Usage: bash deploy.sh /home/shehzad/public_html
# Only the build-owned folders below are synchronised. Everything else in the web root is left alone, in particular api/ (it holds the
# server-only config.php), .well-known/ and any other file that is not part of the build.
set -eu

DEST="${1:?destination web root required}"
SRC="$(cd "$(dirname "$0")" && pwd)"
OWNED="about assets blog css fonts js"

# Sanity: never sync from an incomplete checkout (that would delete the live site).
[ -f "$SRC/index.html" ] && [ -f "$SRC/sitemap.xml" ] && [ -d "$SRC/blog" ] && [ -d "$DEST" ] || { echo "deploy.sh: refusing to run (build or destination missing)" >&2; exit 1; }

cd "$SRC"
# 1) copy / update everything from the build (as before; feed.xml is part of the root files)
cp -R -f about api assets blog css fonts js "$DEST"/
cp -f index.html 404.html sitemap.xml robots.txt feed.xml version.txt .htaccess "$DEST"/

# 2) remove obsolete files inside the build-owned folders, then any folders left empty
for d in $OWNED; do
  [ -d "$DEST/$d" ] || continue
  (cd "$DEST" && find "$d" -type f) | while IFS= read -r f; do
    [ -e "$SRC/$f" ] || { rm -f -- "$DEST/$f"; echo "removed obsolete: $f"; }
  done
  find "$DEST/$d" -depth -mindepth 1 -type d -empty -delete
done
echo "deploy.sh: done"
