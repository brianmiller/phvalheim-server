#!/bin/bash
# Oracle test: a streamed delta that is exactly "0" survives (issue #94, 2.59).
# requires: container
#
# The real test is dev_tools/test-stream-zero-delta.php; it needs php-curl, which only the image
# has. This wrapper copies the working tree's aiproviders.php and the test into the container and
# runs them there as the php-fpm user, so what is measured is the file about to ship.
C="${1:-phvalheim-dev}"
SRC="${2:-}"   # optional: a different aiproviders.php to measure (the mutation control below uses git HEAD)
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
docker exec "$C" true 2>/dev/null || { echo "HARNESS: container $C not running"; exit 2; }
D=/tmp/phv-zero-delta
docker exec "$C" bash -c "rm -rf $D; mkdir -p $D; chmod 777 $D"
docker cp "${SRC:-$REPO/container/nginx/www/includes/aiproviders.php}" "$C:$D/aiproviders.php"
docker cp "$REPO/dev_tools/test-stream-zero-delta.php" "$C:$D/test.php"
docker exec "$C" bash -c "chmod 644 $D/*.php; chown phvalheim: $D $D/*.php"
docker exec -u phvalheim -e AIPROVIDERS_PHP=$D/aiproviders.php "$C" php "$D/test.php"
rc=$?
docker exec "$C" rm -rf "$D"
exit $rc
