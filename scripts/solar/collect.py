#!/usr/bin/env python3
"""Collect the data for the solar generation forecast model.

Run from cron (or by hand) as a user that can read vars.php:
    python3 /var/www/watsonia22.com/scripts/solar/collect.py [--only solarman,weather,pvgis]

Everything is stored under scripts/solar/data/ (not served, see .htaccess):
  solarman/YYYY-MM-DD.json.gz   raw 5-minute inverter readings, one file per local day
  weather/<source>_YYYY.json.gz Open-Meteo hourly data per source and year (UTC)
  pvgis/                        long-term radiation and horizon profile for the roof

Already-downloaded complete days/years are skipped, so re-runs only fetch what is new.
Uses only the Python standard library (no pip on this server).
"""

import datetime as dt
import gzip
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
DATA = os.path.join(HERE, 'data')

# From Solarman's station record (station/v1.0/list)
LAT, LON = -33.936646, 18.427981
STATION_START = dt.date(2023, 7, 31)
LOCAL_TZ = dt.timezone(dt.timedelta(hours=2))  # Cape Town, no DST

HOURLY_VARS = [
    'shortwave_radiation', 'direct_normal_irradiance', 'diffuse_radiation',
    'cloud_cover', 'cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high',
    'temperature_2m', 'relative_humidity_2m', 'wind_speed_10m', 'precipitation',
]

# Open-Meteo sources: (name, base url, variables, first available date)
WEATHER_SOURCES = [
    # Reanalysis (ERA5 family): what the weather actually was, ~5 days behind
    ('archive', 'https://archive-api.open-meteo.com/v1/archive', HOURLY_VARS, STATION_START),
    # Satellite-derived radiation: best measure of the radiation that really reached the roof
    ('satellite', 'https://satellite-api.open-meteo.com/v1/archive',
     ['shortwave_radiation', 'direct_normal_irradiance', 'diffuse_radiation'], STATION_START),
    # The forecast as issued one day ahead, to train/test "tomorrow" predictions honestly
    ('dayahead', 'https://previous-runs-api.open-meteo.com/v1/forecast',
     [v + '_previous_day1' for v in HOURLY_VARS], dt.date(2024, 1, 1)),
    # Same-day forecast (first hours of each run), close to what we know "today"
    ('sameday', 'https://historical-forecast-api.open-meteo.com/v1/forecast', HOURLY_VARS, STATION_START),
]

FIVE_MIN_FIELDS = ['dateTime', 'generationPower', 'usePower', 'batterySoc', 'batteryPower',
                   'chargePower', 'dischargePower', 'purchasePower', 'wirePower']


def log(msg):
    print(f"{dt.datetime.now():%H:%M:%S} {msg}", flush=True)


def http_json(url, body=None, headers=None, tries=4):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, headers=headers or {})
    for attempt in range(tries):
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read())
        except Exception as e:
            if attempt == tries - 1:
                raise
            log(f"  retry after error: {e}")
            time.sleep(5 * (attempt + 1))


def save_gz(path, obj):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.tmp'
    with gzip.open(tmp, 'wt') as f:
        json.dump(obj, f, separators=(',', ':'))
    os.replace(tmp, path)


# --- Solarman ---------------------------------------------------------------

def php_defines(prefix):
    """Read the define('<prefix>...', value) settings from vars.php as strings."""
    with open(os.path.join(ROOT, 'vars.php')) as f:
        found = re.findall(r"define\('(" + prefix + r"\w+)',\s*(?:'([^']*)'|(\d+))\)", f.read())
    return {name: quoted or number for name, quoted, number in found}


def solarman_token():
    # Reuse the PHP helper so the token cache stays shared with solar.php
    out = subprocess.run(
        ['php', '-r', 'include "vars.php"; require "solarman_lib.php"; echo getAccessToken();'],
        cwd=ROOT, capture_output=True, text=True, check=True).stdout.strip()
    if not out:
        sys.exit("collect: could not get a Solarman token")
    return out


def collect_solarman():
    cfg = php_defines('SOLAR_')
    token = solarman_token()
    url = f"https://{cfg['SOLAR_URL']}/station/v1.0/history?language=en"
    headers = {'Content-Type': 'application/json', 'Authorization': 'bearer ' + token}
    today = dt.datetime.now(LOCAL_TZ).date()

    day = STATION_START
    fetched = 0
    while day <= today:
        path = os.path.join(DATA, 'solarman', f"{day}.json.gz")
        # Past days never change; always refresh today and yesterday
        if not os.path.exists(path) or day >= today - dt.timedelta(days=1):
            resp = http_json(url, {'stationId': cfg['SOLAR_STATIONID'], 'startTime': str(day),
                                   'endTime': str(day), 'timeType': 1}, headers)
            if not resp.get('success'):
                sys.exit(f"collect: Solarman error on {day}: {resp.get('msg')}")
            items = [{k: it.get(k) for k in FIVE_MIN_FIELDS} for it in resp.get('stationDataItems', [])]
            save_gz(path, items)
            fetched += 1
            if fetched % 50 == 0:
                log(f"  solarman: {day} ({fetched} days fetched)")
            time.sleep(1)  # be gentle with the API
        day += dt.timedelta(days=1)
    log(f"solarman: fetched {fetched} days")


# --- Open-Meteo -------------------------------------------------------------

def collect_weather():
    today = dt.datetime.now(dt.timezone.utc).date()
    for name, base, variables, first in WEATHER_SOURCES:
        for year in range(first.year, today.year + 1):
            start = max(first, dt.date(year, 1, 1))
            end = min(dt.date(year, 12, 31), today)
            path = os.path.join(DATA, 'weather', f"{name}_{year}.json.gz")
            if os.path.exists(path) and year < today.year:
                continue
            params = {'latitude': LAT, 'longitude': LON, 'start_date': start, 'end_date': end,
                      'hourly': ','.join(variables), 'timezone': 'GMT'}
            if name == 'satellite':
                params['models'] = 'satellite_radiation_seamless'
            resp = http_json(base + '?' + urllib.parse.urlencode(params))
            if resp.get('error'):
                log(f"  weather {name} {year}: {resp.get('reason')}")
                continue
            save_gz(path, resp)
            log(f"weather: {name} {year} ({len(resp['hourly']['time'])} hours)")
            time.sleep(2)


# --- PVGIS (EU JRC) ---------------------------------------------------------

def collect_pvgis():
    base = 'https://re.jrc.ec.europa.eu/api/v5_3/'
    loc = {'lat': LAT, 'lon': LON, 'outputformat': 'json'}
    jobs = {
        # Terrain horizon around the house (Table Mountain)
        'horizon': ('printhorizon', {}),
        # Hourly horizontal radiation 2005-2023, with horizon shading, for climatology
        'hourly_horizontal': ('seriescalc', {'startyear': 2005, 'endyear': 2023, 'components': 1}),
        # Long-term monthly output of an 8 kWp optimally-tilted north-facing system
        'pvcalc_8kw': ('PVcalc', {'peakpower': 8, 'loss': 14, 'optimalangles': 1}),
    }
    for name, (endpoint, params) in jobs.items():
        path = os.path.join(DATA, 'pvgis', f"{name}.json.gz")
        if os.path.exists(path):
            continue
        resp = http_json(base + endpoint + '?' + urllib.parse.urlencode({**loc, **params}))
        save_gz(path, resp)
        log(f"pvgis: {name}")


if __name__ == '__main__':
    only = None
    if '--only' in sys.argv:
        only = set(sys.argv[sys.argv.index('--only') + 1].split(','))
    for name, fn in [('weather', collect_weather), ('pvgis', collect_pvgis), ('solarman', collect_solarman)]:
        if only is None or name in only:
            fn()
