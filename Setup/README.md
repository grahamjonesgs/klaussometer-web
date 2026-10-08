# watsonia22.com server: rebuild guide

How to rebuild the home monitoring server from scratch: the web dashboard, the MQTT broker the
sensors publish to, Node-RED (which stores readings in MariaDB), the database itself, the solar
forecast, and every cron job and backup.

Everything needed is in this repo except secrets. Those come from the weekly config backup in S3,
or are re-created by hand (marked **secret** below).

## What runs on the server

| Component | Version (Oct 2026) | Port | Role |
|---|---|---|---|
| Ubuntu | 26.04 LTS, on AWS EC2 (eu-west-1) | 22 | Host. 1 vCPU, 1 GB RAM, 4 GB swap file. Clock and database in **UTC**. |
| Apache + PHP | 2.4 / PHP 8.5 | 80, 443 | Serves this repo from `/var/www/watsonia22.com` (Let's Encrypt certificate). |
| Mosquitto | 2.0 | 1883 | MQTT broker. Sensors and Klaussometer displays publish readings here. |
| Node-RED | 5.0 | 1880 (localhost only) | Subscribes to sensor topics and writes them to MariaDB. Editor at `https://watsonia22.com/node-red/`, through Apache. |
| MariaDB | 11.8 | 3306 (localhost only) | `readings` database. Scheduled events build hourly and daily averages. |
| Python | 3.14 (standard library only) | | Solar generation forecast, `scripts/solar/`. |
| AWS CLI | 2 | | Uploads backups to `s3://watsonia22-backups` using the instance's IAM role. |

Data flow: sensors → MQTT `room/type/set` topics → Node-RED → `rec_data` table → MariaDB events →
`hourly_avg` / `daily_avg` → PHP pages (`current.html`, `graph.html`, `system_status.php`).

## Repo layout for the rebuild

| Path | What it is |
|---|---|
| `Setup/README.md` | This guide |
| `Setup/cron/crontab-ubuntu.txt`, `crontab-www-data.txt` | The exact crontabs (see [Cron jobs](#cron-jobs)) |
| `Setup/bin/backup_readings.sh`, `backup_config.sh` | Backup scripts, installed to `/home/ubuntu/bin` |
| `Setup/database/schema.sql` | Tables, scheduled events and users for an empty database |
| `Setup/mosquitto/default.conf` | Mosquitto listener config |
| `Setup/apache/*.conf` | Apache virtual hosts (HTTP redirect + HTTPS) |
| `Setup/node-red/flows.json` | Node-RED flows (no credentials in it) |
| `Setup/node-red/package.json`, `nodered.service` | Node-RED palette nodes and systemd unit |
| `vars_example.php` | Template for `vars.php`, which holds all PHP secrets (**secret**, not in git) |
| `scripts/daily_report.php` | Daily AI house report (Claude API), PHP with Composer packages |
| `scripts/solar/` | Solar forecast: `collect.py`, `model.py`, fitted `data/model.json`, weather and PVGIS data |

Not in git: `vars.php`, `cache/`, `scripts/vendor/` (run `composer install`), the firmware folders
`klaussometer/` and `sensor/`, and `scripts/solar/data/solarman/`. The Solarman files are 5-minute
house power readings; they reveal when the house is empty, so they stay off this public repo.
`collect.py` downloads them again.

## Fast path: restore from backups

Use this when the old server is gone but S3 still has the backups. The bucket is
`s3://watsonia22-backups` in eu-west-1:

- `db/readings_YYYY-MM-DD.sql.gz` is the nightly database dump, including the scheduled events.
- `config/config_YYYY-MM-DD.tgz` is the weekly config backup. It holds `vars.php`, `~/.node-red`
  (flows, encrypted credentials and their key, admin login), `~/bin`, `/etc/mosquitto` (including
  the MQTT password file), Apache sites, `/etc/mysql`, `php.ini` and both crontabs as text.

1. Do [steps 1 to 3](#1-server-and-packages) of the full build: server, packages, repo.
2. Fetch the latest backups:
   ```bash
   aws s3 ls s3://watsonia22-backups/config/ | tail -1
   aws s3 ls s3://watsonia22-backups/db/ | tail -1
   aws s3 cp s3://watsonia22-backups/config/config_YYYY-MM-DD.tgz /tmp/
   aws s3 cp s3://watsonia22-backups/db/readings_YYYY-MM-DD.sql.gz /tmp/
   mkdir /tmp/config && tar xzf /tmp/config_YYYY-MM-DD.tgz -C /tmp/config
   ```
3. Put the config back where it came from. The tar paths mirror the filesystem:
   ```bash
   cp /tmp/config/var/www/watsonia22.com/vars.php /var/www/watsonia22.com/
   cp -a /tmp/config/home/ubuntu/.node-red /home/ubuntu/
   cp -a /tmp/config/home/ubuntu/bin /home/ubuntu/
   sudo cp -a /tmp/config/etc/mosquitto/. /etc/mosquitto/
   sudo cp -a /tmp/config/etc/apache2/sites-available/. /etc/apache2/sites-available/
   ```
   Compare `/tmp/config/etc/mysql` with the fresh `/etc/mysql` rather than copying it over
   wholesale; the only change that matters is `event_scheduler = ON` (step 5).
4. Continue with the full build from [step 4](#4-apache-and-https). Skip the steps that
   create secrets, and restore the database instead of loading `schema.sql`:
   ```bash
   sudo mariadb -e "CREATE DATABASE readings"
   gunzip -c /tmp/readings_YYYY-MM-DD.sql.gz | sudo mariadb readings
   ```
   Then create the users from the bottom of `Setup/database/schema.sql` (users are not in the dump).

## Full build

### 1. Server and packages

EC2 instance with Ubuntu 26.04, an Elastic IP that `watsonia22.com` and `www.watsonia22.com`
point to, and:

- **IAM role** allowing `s3:PutObject` on `watsonia22-backups/*`, plus `s3:ListBucket`/`s3:GetObject`
  for restores. The bucket has a lifecycle rule for retention; the role cannot delete.
- **Security group** inbound: 22 (SSH), 80, 443, 1883 (MQTT, for the sensors). Do not open
  1880: Node-RED only listens on 127.0.0.1, and Apache serves its editor over HTTPS (step 7).
  The firewall on the box (`ufw`) is off; the security group is the firewall.

```bash
sudo timedatectl set-timezone Etc/UTC

# 4 GB swap: 1 GB RAM is not enough for MariaDB + Node-RED + the solar model fit
sudo fallocate -l 4G /swapfile2 && sudo chmod 600 /swapfile2
sudo mkswap /swapfile2 && sudo swapon /swapfile2
echo '/swapfile2 none swap sw 0 0' | sudo tee -a /etc/fstab

sudo apt update
sudo apt install apache2 php libapache2-mod-php php-mysql php-curl php-mbstring php-xml \
    php-zip php-intl php-bcmath composer mariadb-server mosquitto mosquitto-clients \
    nodejs npm python3 awscli git
sudo snap install --classic certbot && sudo ln -s /snap/bin/certbot /usr/bin/certbot
```

### 2. Repo and permissions

The site is owned by `ubuntu` with group `www-data`, setgid so new files keep the group:

```bash
sudo usermod -aG www-data ubuntu        # log out and in again
sudo git clone https://github.com/grahamjonesgs/klaussometer-web /var/www/watsonia22.com
sudo chown -R ubuntu:www-data /var/www/watsonia22.com
sudo chmod -R g+rwxs /var/www/watsonia22.com
# cache/ holds the Solarman token and daily report; only www-data writes it
mkdir /var/www/watsonia22.com/cache && sudo chown www-data:www-data /var/www/watsonia22.com/cache
```

### 3. Secrets: vars.php

```bash
cp /var/www/watsonia22.com/vars_example.php /var/www/watsonia22.com/vars.php
chmod 640 /var/www/watsonia22.com/vars.php     # owner and www-data only
```

Fill in (**secret**): the read-only database user (`reader`), Solarman API app ID, secret, login
and station ID, MQTT user, dashboard login (`AUTH_PASSWORD_HASH`, generate with
`php -r "echo password_hash('...', PASSWORD_DEFAULT);"`), and `ANTHROPIC_API_KEY`.
`.htaccess` blocks web access to `vars.php`, `cache/` and `scripts/`.

Install the PHP packages for the daily report:

```bash
cd /var/www/watsonia22.com/scripts && composer install
```

### 4. Apache and HTTPS

```bash
sudo cp /var/www/watsonia22.com/Setup/apache/watsonia22.com.conf /etc/apache2/sites-available/
sudo a2enmod rewrite ssl proxy proxy_http
sudo a2ensite watsonia22.com && sudo a2dissite 000-default
sudo systemctl reload apache2
sudo certbot --apache -d watsonia22.com -d www.watsonia22.com
```

Certbot creates `watsonia22.com-le-ssl.conf` and the HTTP-to-HTTPS redirect. Compare it with
`Setup/apache/watsonia22.com-le-ssl.conf`, which adds the `<Directory>` block with
`AllowOverride All` (needed for `.htaccess`) and the proxy for the Node-RED editor:

```apache
RedirectMatch 301 ^/node-red$ /node-red/
ProxyPass        /node-red/ http://127.0.0.1:1880/node-red/ upgrade=websocket
ProxyPassReverse /node-red/ http://127.0.0.1:1880/node-red/
```

`upgrade=websocket` carries the editor's live connection (`/node-red/comms`). Renewal runs from
certbot's own systemd timer (`snap.certbot.renew.timer`), not cron.

To stop Apache announcing its version and OS, set these in
`/etc/apache2/conf-available/security.conf`:

```apache
ServerTokens Prod
ServerSignature Off
```

### 5. MariaDB

Turn on the event scheduler permanently. It drives the hourly and daily averages. Add to
`/etc/mysql/mariadb.cnf`:

```ini
[mysqld]
event_scheduler = ON
```

Keep `bind-address = 127.0.0.1` (the default) so the database is not reachable from outside.

```bash
sudo systemctl restart mariadb
# Fresh database (edit the CHANGE_ME passwords first), or restore a backup as in the fast path
sudo mariadb < /var/www/watsonia22.com/Setup/database/schema.sql
sudo mariadb readings -e "SHOW EVENTS"     # expect 3 events, ENABLED
```

The `ubuntu@localhost` user logs in through the unix socket with no password. The nightly
backup uses it.

### 6. Mosquitto

```bash
sudo cp /var/www/watsonia22.com/Setup/mosquitto/default.conf /etc/mosquitto/conf.d/default.conf
sudo mosquitto_passwd -c /etc/mosquitto/passwd reporter     # secret: the password the sensors use
sudo chown mosquitto:mosquitto /etc/mosquitto/passwd && sudo chmod 600 /etc/mosquitto/passwd
sudo systemctl restart mosquitto
mosquitto_sub -h localhost -u reporter -P '...' -t '#' -v    # readings should arrive within minutes
```

The sensors, the Klaussometer displays and Node-RED all use the `reporter` user, so the password
must match what is in their firmware. Anonymous access is off. There is only a plain listener on
1883.

**Optional: TLS on 8883.** Not enabled at present. The certificate links already exist on the
current server:

```bash
sudo mkdir -p /etc/mosquitto/certs
sudo ln -s /etc/letsencrypt/live/watsonia22.com/fullchain.pem /etc/mosquitto/certs/server.crt
sudo ln -s /etc/letsencrypt/live/watsonia22.com/privkey.pem   /etc/mosquitto/certs/server.key
sudo ln -s /etc/letsencrypt/live/watsonia22.com/chain.pem     /etc/mosquitto/certs/ca.crt
sudo chown -h mosquitto:mosquitto /etc/mosquitto/certs/*
```

Then add this to `default.conf`:

```
listener 8883
protocol mqtt
certfile /etc/mosquitto/certs/server.crt
keyfile /etc/mosquitto/certs/server.key
require_certificate false
tls_version tlsv1.2
```

`/etc/letsencrypt/live` and `/etc/letsencrypt/archive` need mode 755 so Mosquitto can read the
certificate. Add a certbot deploy hook that runs `systemctl restart mosquitto` so renewals are
picked up.

### 7. Node-RED

Node.js 24 is installed through `n`, and Node-RED globally:

```bash
sudo npm install -g n && sudo n 24
sudo npm install -g node-red@5
mkdir -p ~/.node-red && cd ~/.node-red
cp /var/www/watsonia22.com/Setup/node-red/package.json .
npm install                                    # node-red-node-mysql and node-red-admin
cp /var/www/watsonia22.com/Setup/node-red/flows.json .
node-red                                       # first run creates settings.js; stop with Ctrl-C
```

Edit `~/.node-red/settings.js`. Require a login for the editor (**secret**), listen only on the
server itself, and serve the editor under `/node-red` so Apache can proxy it (step 4):

```js
adminAuth: {
    type: "credentials",
    users: [{ username: "admin", password: "<hash>", permissions: "*" }]
},
uiHost: "127.0.0.1",
httpAdminRoot: '/node-red',
```

Generate the hash with `node-red admin hash-pw`. Keep the files holding secrets readable only by
their owner:

```bash
sudo chown root:ubuntu ~/.node-red/settings.js && sudo chmod 640 ~/.node-red/settings.js
chmod 600 ~/.node-red/flows_cred.json ~/.node-red/.config.runtime.json ~/.node-red/.config.users.json
```

Run it as a service:

```bash
sudo cp /var/www/watsonia22.com/Setup/node-red/nodered.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now nodered
```

Open `https://watsonia22.com/node-red/` and log in. In the flow, open the **local mqtt** broker node
(`localhost:1883`) and enter the `reporter` login. Open the **LocalDB** MySQL node
(`127.0.0.1:3306`, database `readings`) and enter a database user that can insert, such as the
admin user. Then deploy. These credentials are encrypted in `flows_cred.json` with a key in
`.config.runtime.json`, so a `.node-red` restored from the config backup needs no re-entry.

The flow subscribes to `{bedroom,livingroom,guest,cave,outside}/tempset-{ambient,humidity}/set`,
`outside/battery/set` and `kitchen/{co2,pm25}/set`, and inserts each reading into `rec_data`.

### 8. Solar forecast

Plain Python 3, standard library only. There is no pip on the server, so nothing needs installing.
`model.json` (the fitted model) and the weather and PVGIS history are in the repo. The Solarman
5-minute history is not.

```bash
cd /var/www/watsonia22.com/scripts/solar
# Runs as www-data like the cron jobs; the group-writable setgid repo (step 2) lets it write data/
# Re-download Solarman history since July 2023 (~20-30 min) and refresh the weather
sudo -u www-data python3 collect.py
sudo -u www-data python3 model.py fit        # ~1 min
sudo -u www-data python3 model.py forecast   # writes data/forecast.json for the dashboard
python3 model.py evaluate                    # optional back-test, prints accuracy
```

`solar_forecast.php` serves `data/forecast.json` to the Solar Forecast cards on `current.html`.
Each forecast is also published as a retained MQTT message on `solar/forecast` for the Klaussometer
displays, using the MQTT login in `vars.php`. It is compact because the firmware's MQTT buffer is
small:

```json
{"ts":1791463917,"at":"14:51","today":{"exp":22.6,"pot":25.6,"made":19.7,"full":"14:00"},"tomorrow":{"exp":14.4,"pot":14.4,"full":null}}
```

`exp` is expected kWh after throttling, `pot` what the roof could make, `made` generated so far
today, and `full` the time the battery is expected to be full (`null` if it won't fill). `ts` is
when the forecast was made (Unix time), so a display can tell when it is stale.
The coordinates and station start date are at the top of `collect.py` and `model.py`. The
module docstrings explain the method (throttling when the battery is full, two panel groups,
learned shade map).

### 9. Backup scripts

```bash
mkdir -p ~/bin ~/backups/mariadb ~/backups/config
cp /var/www/watsonia22.com/Setup/bin/*.sh ~/bin/ && chmod 700 ~/bin/*.sh
~/bin/backup_readings.sh && ~/bin/backup_config.sh    # test: both should say "uploaded"
```

`backup_config.sh` uses `sudo -n crontab -u www-data -l`, so `ubuntu` needs passwordless sudo
(the EC2 default).

### 10. Cron jobs

Install both crontabs exactly as committed:

```bash
crontab /var/www/watsonia22.com/Setup/cron/crontab-ubuntu.txt
sudo crontab -u www-data /var/www/watsonia22.com/Setup/cron/crontab-www-data.txt
```

## Cron jobs

All times are UTC. Cape Town is UTC+2 with no daylight saving.

| User | Schedule | Cape Town time | Job | Log |
|---|---|---|---|---|
| ubuntu | `30 3 * * *` | 05:30 daily | `~/bin/backup_readings.sh`: dumps `readings` (with events), keeps 14 days locally, uploads to `s3://watsonia22-backups/db/` | `~/backups/mariadb/backup.log` |
| ubuntu | `0 3 * * 0` | 05:00 Sunday | `~/bin/backup_config.sh`: tars secrets and config (see fast path), keeps 8 weeks, uploads to `s3://watsonia22-backups/config/` | `~/backups/config/backup.log` |
| www-data | `0 4 * * *` | 06:00 daily | `scripts/daily_report.php`: summarises yesterday with Claude, writes `cache/daily_report.json` for the dashboard | `cache/daily_report.log` |
| www-data | `10 * * * *` | hourly at :10 | `scripts/solar/collect.py --only solarman` then `model.py forecast`: today's inverter readings, then a new forecast for today and tomorrow, also published to MQTT `solar/forecast` (retained) | `scripts/solar/data/solar.log` |
| www-data | `30 2 * * 0` | 04:30 Sunday | `scripts/solar/collect.py --only weather` then `model.py fit`: refreshes weather history and refits the solar model | `scripts/solar/data/solar.log` |

Jobs scheduled elsewhere, which need no setup beyond the steps above:

- **MariaDB events**, from `schema.sql` or the backup: `hourly_aggregation_event` (every hour),
  `daily_aggregation_event` (daily at midnight UTC), `cleanup_event_log` (weekly, drops log rows
  older than 30 days). Their runs show in `event_log` and on `system_status.php`.
- **Certbot renewal**: `snap.certbot.renew.timer`.
- **Ubuntu defaults** in `/etc/cron.d` (`php` session cleanup, `e2scrub_all`) come with the
  packages.

The solar jobs run as `www-data` because only it can refresh the Solarman token in `cache/`.

## Checks after a rebuild

- `https://watsonia22.com/current.html` shows rooms, air quality, solar, the forecast and
  yesterday's report (the report appears after the first 04:00 UTC run).
- `https://watsonia22.com/system_status.php` shows recent rows in every table and the events
  running.
- `sudo mariadb readings -e "SELECT MAX(dt) FROM rec_data"` is within the last few minutes.
- `https://watsonia22.com/scripts/` and `/cache/` return 404.
- `https://watsonia22.com/node-red/` shows the Node-RED login, and `http://watsonia22.com:1880`
  does not connect.
- `mosquitto_sub -h localhost -u reporter -P '...' -t solar/forecast -C 1` prints the forecast
  straight away (it is retained).
- The next morning, both backup logs end in `uploaded to s3://...`.

## Keeping this guide current

When something on the server changes, update the copy here and commit it:

```bash
cd /var/www/watsonia22.com
crontab -l > Setup/cron/crontab-ubuntu.txt
sudo crontab -u www-data -l > Setup/cron/crontab-www-data.txt
cp ~/.node-red/flows.json Setup/node-red/flows.json
cp ~/bin/backup_*.sh Setup/bin/
cp /etc/mosquitto/conf.d/default.conf Setup/mosquitto/
```

For database changes, edit `Setup/database/schema.sql`. Compare it with
`sudo mariadb readings -e "SHOW CREATE TABLE ..."` and `information_schema.EVENTS`.
