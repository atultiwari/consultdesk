#!/usr/bin/env bash
# Builds the ConsultDesk release zip: build/consultdesk-<version>.zip, containing
#   public/            → the contents go into the booking site's web folder (e.g. public_html/book)
#   consultdesk-app/   → goes next to public_html, outside the web folder
# Needs node/npm and composer (or COMPOSER="docker run --rm -v \$PWD:/app -w /app composer:2").
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(sed -n "s/.*CURRENT = '\([^']*\)'.*/\1/p" "$ROOT/api/src/Version.php")}"
NAME="consultdesk-$VERSION"
OUT="$ROOT/build/$NAME"
COMPOSER="${COMPOSER:-composer}"

rm -rf "$OUT" "$ROOT/build/$NAME.zip"
mkdir -p "$OUT/public/api" "$OUT/consultdesk-app"

echo "› Building the web app"
(cd "$ROOT/web" && npm ci --no-audit --no-fund && npm run build)
cp -R "$ROOT/web/dist/." "$OUT/public/"
cp "$ROOT/release/public.htaccess" "$OUT/public/.htaccess"
cp "$ROOT/release/api-index.php" "$OUT/public/api/index.php"

echo "› Copying the API"
APP="$OUT/consultdesk-app"
cp -R "$ROOT/api/src" "$ROOT/api/bin" "$ROOT/api/migrations" "$APP/"
cp "$ROOT/api/http.php" "$ROOT/api/composer.json" "$ROOT/api/composer.lock" "$APP/"
rm -f "$APP/bin/seed-dev.php" "$APP/bin/coverage-check.php"
cp "$ROOT/release/config.example.php" "$APP/config.example.php"
cp "$ROOT/release/app.htaccess" "$APP/.htaccess"
for dir in storage/media storage/backups; do
  mkdir -p "$APP/$dir"
  cp "$ROOT/release/app.htaccess" "$APP/$dir/.htaccess"
done

echo "› Installing PHP libraries (production only)"
(cd "$APP" && $COMPOSER install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress ${COMPOSER_EXTRA:-})
rm -f "$APP/composer.json" "$APP/composer.lock"

cat > "$OUT/README.txt" <<TXT
ConsultDesk $VERSION

1. Upload the contents of public/ into the booking site's web folder (for example public_html/book).
2. Upload consultdesk-app/ next to public_html (NOT inside it).
3. Open https://<your booking site>/install and follow the steps.

Full guide: docs/DEPLOY-HOSTINGER.md in the repository.
TXT

echo "› Zipping"
(cd "$ROOT/build" && zip -qr "$NAME.zip" "$NAME")
echo "Built build/$NAME.zip ($(du -h "$ROOT/build/$NAME.zip" | cut -f1))"
