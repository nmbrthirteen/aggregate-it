#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: bin/release.sh X.Y.Z" >&2
	exit 1
fi

cd "$(dirname "$0")/.."

perl -i -pe "s/^( \* Version:\s+).*/\${1}${VERSION}/" aggregate-it.php
perl -i -pe "s/(define\( 'AGGREGATE_IT_VERSION', ')[^']*(' \))/\${1}${VERSION}\${2}/" aggregate-it.php

php -l aggregate-it.php >/dev/null

git add aggregate-it.php
git commit -m "Release ${VERSION}"
git tag "v${VERSION}"
git push origin "$(git rev-parse --abbrev-ref HEAD)" "v${VERSION}"

echo "Tagged v${VERSION}. GitHub Actions will build aggregate-it.zip and publish the release."
