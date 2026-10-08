#!/bin/bash
# Weekly backup of server config that would be painful to rebuild, run from
# ubuntu's crontab. Uploaded to S3 unencrypted (private bucket, SSE-S3 at rest).
# Contains secrets (vars.php, Node-RED credentials and key, MQTT passwords).
set -euo pipefail

BACKUP_DIR=/home/ubuntu/backups/config
KEEP_DAYS=56
S3_DEST=s3://watsonia22-backups/config/

mkdir -p -m 700 "$BACKUP_DIR"
stamp=$(date +%F)
out="$BACKUP_DIR/config_$stamp.tgz"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Crontabs live in a root-only spool, so export them as text
crontab -l > "$work/crontab-ubuntu.txt" 2>/dev/null || true
sudo -n crontab -u www-data -l > "$work/crontab-www-data.txt" 2>/dev/null || true

tar czf "$out.tmp" --warning=no-file-changed \
    --exclude=.node-red/node_modules \
    --exclude=etc/mysql/debian.cnf \
    -C / \
        var/www/watsonia22.com/vars.php \
        home/ubuntu/.node-red \
        home/ubuntu/bin \
        etc/mosquitto \
        etc/apache2/sites-available \
        etc/mysql \
        etc/php/8.5/apache2/php.ini \
    -C "$work" crontab-ubuntu.txt crontab-www-data.txt
chmod 600 "$out.tmp"
mv "$out.tmp" "$out"

find "$BACKUP_DIR" -name 'config_*.tgz' -mtime +$KEEP_DAYS -delete
echo "$(date '+%F %T') config backup ok: $out ($(du -h "$out" | cut -f1))"

if aws s3 cp --only-show-errors "$out" "$S3_DEST"; then
    echo "$(date '+%F %T') uploaded to $S3_DEST"
else
    echo "$(date '+%F %T') S3 UPLOAD FAILED for $out"
    exit 1
fi
