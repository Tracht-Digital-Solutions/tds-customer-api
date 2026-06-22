#!/usr/bin/env bash
# Assemble a self-contained production bundle into ./dist:
#   - production dependencies (composer --no-dev --optimize-autoloader)
#   - the phinx migration runner re-added from require-dev (the host runs
#     `vendor/bin/phinx migrate -e production` from the bundle)
#   - repo source minus tests / CI / git metadata, with vendor/ kept
# Arg $1 = channel label (dev | release) stamped into BUILD_INFO.json.
# Config is host-side .env, so dev and release bundles are identical content.
set -euo pipefail

CHANNEL="${1:-dev}"

composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

constraint=$(jq -r '."require-dev"."robmorgan/phinx" // empty' composer.json)
if [ -n "$constraint" ]; then
  composer require "robmorgan/phinx:$constraint" \
    --no-interaction --no-progress --update-no-dev --optimize-autoloader
fi

rm -rf dist && mkdir dist
rsync -a \
  --exclude '.git' --exclude '.github' --exclude '.gitignore' \
  --exclude 'tests' --exclude 'phpunit.xml' --exclude '.phpunit.cache' \
  --exclude 'dist' --exclude '.env' \
  ./ dist/

cat > dist/BUILD_INFO.json <<EOF
{
  "channel": "${CHANNEL}",
  "commit": "${GITHUB_SHA:-unknown}",
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "workflow_run": "${GITHUB_RUN_ID:-local}"
}
EOF

echo "Assembled ${CHANNEL} bundle ($(du -sh dist | cut -f1))."
