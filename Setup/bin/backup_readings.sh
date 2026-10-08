#!/bin/bash
# Nightly dump of the readings database, run from ubuntu's crontab.
# Kept locally for KEEP_DAYS and copied to S3, where a lifecycle rule handles
# retention (the instance role can upload but not delete).
# Logs in as MariaDB user 'ubuntu' via unix_socket, so no password is stored.
# Restore with: gunzip -c FILE | sudo mariadb readings
set -euo pipefail

BACKUP_DIR=/home/ubuntu/backups/mariadb
KEEP_DAYS=14
S3_DEST=s3://watsonia22-backups/db/

stamp=$(date +%F)
tmp="$BACKUP_DIR/.readings_$stamp.sql.gz.tmp"
out="$BACKUP_DIR/readings_$stamp.sql.gz"

# Write to a temp file first so a failed dump never looks like a good backup
nice -n 19 ionice -c3 mysqldump --single-transaction --quick --events \
    --no-tablespaces readings | gzip > "$tmp"
mv "$tmp" "$out"

find "$BACKUP_DIR" -name 'readings_*.sql.gz' -mtime +$KEEP_DAYS -delete
echo "$(date '+%F %T') backup ok: $out ($(du -h "$out" | cut -f1))"

# Local copy is already safe, so report an S3 failure without losing it
if aws s3 cp --only-show-errors "$out" "$S3_DEST"; then
    echo "$(date '+%F %T') uploaded to $S3_DEST"
else
    echo "$(date '+%F %T') S3 UPLOAD FAILED for $out"
    exit 1
fi
