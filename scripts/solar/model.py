#!/usr/bin/env python3
"""Solar generation model for 22 Watsonia Street: fit, evaluate and forecast.

    python3 model.py fit        fit roof orientation, efficiency and shade map -> data/model.json
    python3 model.py evaluate   back-test against past days (day-ahead and same-day forecasts)
    python3 model.py forecast   predict today and tomorrow -> data/forecast.json, and a compact
                                retained MQTT message on solar/forecast for the Klaussometer

How it works
  1. Solarman 5-minute readings are averaged to hourly generation. The system cannot
     export, so once the battery is nearly full the inverter throttles the panels to
     the house load. Those "curtailed" hours under-report what the roof could make and
     are left out of fitting.
  2. Hourly radiation (direct, diffuse) is projected onto the two panel groups seen on
     the aerial photo (north-northwest and east-northeast). Their exact directions,
     pitch and relative size are not recorded anywhere, so they are found by grid search.
  3. Power = a1 * beam_on_group1 + a2 * beam_on_group2 + b * diffuse_on_roof, derated
     for cell temperature and capped at the highest hourly output ever seen.
  4. A shade map (ratio actual/modelled by sun azimuth and elevation) captures Table Mountain, trees and neighbours.
Standard library only.
"""

import datetime as dt
import glob
import gzip
import json
import math
import os
import socket
import struct
import sys
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, 'data')
MODEL_FILE = os.path.join(DATA, 'model.json')
FORECAST_FILE = os.path.join(DATA, 'forecast.json')
# Retained MQTT message for the Klaussometer displays (compact: the firmware's MQTT buffer is small)
MQTT_TOPIC = 'solar/forecast'

LAT, LON = -33.936646, 18.427981
LOCAL_TZ = dt.timezone(dt.timedelta(hours=2))
UTC = dt.timezone.utc

CURTAIL_SOC = 97          # battery % at which the inverter starts following the load
MIN_SAMPLES = 8           # 5-minute readings needed for an hour to count
FIRST_GOOD_DAY = dt.date(2023, 8, 11)   # earlier days show no generation (commissioning)
TEST_FROM = dt.date(2025, 7, 1)         # evaluate on data after this; fit on data before
SHADE_AZ_STEP, SHADE_EL_STEP = 10, 5    # shade map bin size, degrees
SHADE_PRIOR = 20          # hours of evidence before a shade bin moves away from 1.0
TEMP_COEFF = -0.004       # per °C above 25 °C cell temperature
# Radiation used for fitting. Satellite would be best but Open-Meteo only has it from
# Feb 2026; the reanalysis archive covers the whole life of the system.
FIT_SOURCE = 'archive'


# --- Sun position (NOAA) ------------------------------------------------------

def sun_position(t):
    """Sun (elevation, azimuth) in degrees at UTC datetime t. Azimuth 0 = north, 90 = east."""
    doy = t.timetuple().tm_yday
    hour = t.hour + t.minute / 60 + t.second / 3600
    g = 2 * math.pi / 365 * (doy - 1 + (hour - 12) / 24)
    eqtime = 229.18 * (0.000075 + 0.001868 * math.cos(g) - 0.032077 * math.sin(g)
                       - 0.014615 * math.cos(2 * g) - 0.040849 * math.sin(2 * g))
    decl = (0.006918 - 0.399912 * math.cos(g) + 0.070257 * math.sin(g) - 0.006758 * math.cos(2 * g)
            + 0.000907 * math.sin(2 * g) - 0.002697 * math.cos(3 * g) + 0.00148 * math.sin(3 * g))
    tst = hour * 60 + eqtime + 4 * LON
    ha = math.radians(tst / 4 - 180)
    lat = math.radians(LAT)
    cos_zen = math.sin(lat) * math.sin(decl) + math.cos(lat) * math.cos(decl) * math.cos(ha)
    zen = math.acos(max(-1, min(1, cos_zen)))
    az = math.degrees(math.atan2(math.sin(ha), math.cos(ha) * math.sin(lat) - math.tan(decl) * math.cos(lat))) + 180
    return 90 - math.degrees(zen), az % 360


def hour_sun(t_end):
    """Sun position at the middle of the hour ending at t_end (Open-Meteo values are preceding-hour means)."""
    return sun_position(t_end - dt.timedelta(minutes=30))


# --- Data loading ---------------------------------------------------------------

def load_generation():
    """Hourly readings keyed by the UTC end of the hour:
    {t_end: (gen kWh, max SoC %, curtailed, house use kWh, SoC % at end of hour)}."""
    buckets = {}
    for path in sorted(glob.glob(os.path.join(DATA, 'solarman', '*.json.gz'))):
        if os.path.basename(path)[:10] < str(FIRST_GOOD_DAY):
            continue
        with gzip.open(path, 'rt') as f:
            for r in json.load(f):
                if r.get('generationPower') is None:
                    continue
                t = dt.datetime.fromtimestamp(r['dateTime'], UTC)
                t_end = t.replace(minute=0, second=0) + dt.timedelta(hours=1)
                buckets.setdefault(t_end, []).append(
                    (r['dateTime'], r['generationPower'], r.get('batterySoc') or 0, r.get('usePower') or 0))
    hours = {}
    for t_end, rows in buckets.items():
        if len(rows) < MIN_SAMPLES:
            continue
        rows.sort()
        kwh = sum(r[1] for r in rows) / len(rows) / 1000
        use = sum(r[3] for r in rows) / len(rows) / 1000
        max_soc = max(r[2] for r in rows)
        hours[t_end] = (kwh, max_soc, max_soc >= CURTAIL_SOC, use, rows[-1][2])
    return hours


def load_weather(source):
    """{t_end (UTC): {var: value}} for one collected Open-Meteo source."""
    out = {}
    for path in sorted(glob.glob(os.path.join(DATA, 'weather', f'{source}_*.json.gz'))):
        with gzip.open(path, 'rt') as f:
            out.update(parse_open_meteo(json.load(f)))
    return out


def parse_open_meteo(resp):
    h = resp['hourly']
    out = {}
    for i, ts in enumerate(h['time']):
        t = dt.datetime.fromisoformat(ts).replace(tzinfo=UTC)
        row = {k.replace('_previous_day1', ''): v[i] for k, v in h.items() if k != 'time'}
        if row.get('shortwave_radiation') is not None:
            out[t] = row
    return out


def load_horizon():
    """PVGIS horizon profile as {azimuth (0 = north, clockwise): horizon elevation}."""
    with gzip.open(os.path.join(DATA, 'pvgis', 'horizon.json.gz'), 'rt') as f:
        prof = json.load(f)['outputs']['horizon_profile']
    # PVGIS uses 0 = south, -90 = east
    return sorted(((p['A'] + 180) % 360, p['H_hor']) for p in prof)


def horizon_at(horizon, az):
    for (a0, h0), (a1, h1) in zip(horizon, horizon[1:]):
        if a0 <= az <= a1:
            return h0 + (h1 - h0) * (az - a0) / (a1 - a0 or 1)
    return horizon[-1][1]


# --- Physics ----------------------------------------------------------------------

# Panel groups, from the aerial photo: most face roughly north-northwest, a smaller
# group roughly east-northeast. Directions are searched within these ranges
# (degrees, 0 = north, 90 = east); both groups share one roof pitch. The generation
# data alone barely distinguishes layouts (hourly error is dominated by cloud timing),
# so the ranges keep the fit to what the photo shows.
PLANE_SEARCH = [range(-40, 21, 10), range(50, 101, 10)]
TILT_SEARCH = range(10, 36, 5)


def sun_vector(el, az):
    e, a = math.radians(el), math.radians(az)
    return math.cos(e) * math.sin(a), math.cos(e) * math.cos(a), math.sin(e)


def plane_normal(tilt, azimuth):
    t, a = math.radians(tilt), math.radians(azimuth)
    return math.sin(t) * math.sin(a), math.sin(t) * math.cos(a), math.cos(t)


def radiation_inputs(w, el, az, horizon):
    """What the model needs from one hour of weather: (sun vector, beam visible?, dni, dhi, ghi, temp factor)."""
    dni = w.get('direct_normal_irradiance') or 0
    dhi = w.get('diffuse_radiation') or 0
    ghi = w.get('shortwave_radiation') or 0
    visible = el > horizon_at(horizon, az)
    t_air = w.get('temperature_2m')
    tf = 1.0 if t_air is None else 1 + TEMP_COEFF * (t_air + ghi / 800 * 25 - 25)
    return sun_vector(el, az), visible, dni, dhi, ghi, tf


def features(inp, normals, cos_tilt):
    """Per hour: beam on each panel group, then diffuse on the roof (W/m², temperature-derated)."""
    (sx, sy, sz), visible, dni, dhi, ghi, tf = inp
    x = [tf * dni * max(0.0, sx * nx + sy * ny + sz * nz) if visible else 0.0 for nx, ny, nz in normals]
    x.append(tf * (dhi * (1 + cos_tilt) / 2 + 0.2 * ghi * (1 - cos_tilt) / 2))
    return x


def lstsq(rows):
    """Least squares y = sum(c_i * x_i), no intercept. rows: [(x list, y)]. Gaussian elimination."""
    n = len(rows[0][0])
    A = [[0.0] * (n + 1) for _ in range(n)]
    for x, y in rows:
        for i in range(n):
            xi = x[i]
            Ai = A[i]
            for j in range(n):
                Ai[j] += xi * x[j]
            Ai[n] += xi * y
    for i in range(n):
        p = max(range(i, n), key=lambda r: abs(A[r][i]))
        A[i], A[p] = A[p], A[i]
        if abs(A[i][i]) < 1e-12:
            return None
        for r in range(n):
            if r != i:
                f = A[r][i] / A[i][i]
                A[r] = [a - f * b for a, b in zip(A[r], A[i])]
    return [A[i][n] / A[i][i] for i in range(n)]


def shade_key(el, az):
    return f"{int(az // SHADE_AZ_STEP) * SHADE_AZ_STEP}/{int(el // SHADE_EL_STEP) * SHADE_EL_STEP}"


def predict_hour(m, w, t_end, horizon):
    el, az = hour_sun(t_end)
    if el <= 0:
        return 0.0
    normals = [plane_normal(m['tilt'], p['azimuth']) for p in m['planes']]
    x = features(radiation_inputs(w, el, az, horizon), normals, math.cos(math.radians(m['tilt'])))
    kwh = sum(c * xi for c, xi in zip([p['a'] for p in m['planes']] + [m['b']], x))
    kwh *= m['shade'].get(shade_key(el, az), 1.0)
    return max(0.0, min(kwh, m['max_kwh']))


# --- Fit --------------------------------------------------------------------------

def training_rows(gen, weather, until):
    """Clean daylight hours: [(t_end, kWh, weather, el, az)]."""
    rows = []
    for t_end, (kwh, _, curtailed, _, _) in gen.items():
        if curtailed or t_end.date() >= until or t_end not in weather:
            continue
        el, az = hour_sun(t_end)
        if el > 2:
            rows.append((t_end, kwh, weather[t_end], el, az))
    return rows


def fit(until=TEST_FROM, verbose=True):
    gen = load_generation()
    weather = load_weather(FIT_SOURCE)
    horizon = load_horizon()
    rows = training_rows(gen, weather, until)
    inputs = [(radiation_inputs(w, el, az, horizon), kwh) for _, kwh, w, el, az in rows]
    if verbose:
        print(f"fit: {len(rows)} clean daylight hours before {until} "
              f"({sum(1 for v in gen.values() if v[2])} curtailed hours excluded)")

    # Orientation: grid search over pitch and the two directions, keeping the best
    # least-squares fit with every coefficient physically positive
    best = None
    for tilt in TILT_SEARCH:
        cos_tilt = math.cos(math.radians(tilt))
        for az1 in PLANE_SEARCH[0]:
            for az2 in PLANE_SEARCH[1]:
                normals = [plane_normal(tilt, az1), plane_normal(tilt, az2)]
                fr = [(features(inp, normals, cos_tilt), kwh) for inp, kwh in inputs]
                coef = lstsq(fr)
                if not coef or min(coef) <= 0:
                    continue
                sse = sum((sum(c * xi for c, xi in zip(coef, x)) - y) ** 2 for x, y in fr)
                if best is None or sse < best[0]:
                    best = (sse, tilt, (az1 % 360, az2 % 360), coef)
    sse, tilt, azs, coef = best
    m = {'tilt': tilt, 'planes': [{'azimuth': az, 'a': c} for az, c in zip(azs, coef)],
         'b': coef[-1], 'shade': {}, 'rmse_kwh': round(math.sqrt(sse / len(rows)), 3),
         'max_kwh': max(v[0] for v in gen.values()), 'trained_until': str(until),
         'fitted_at': dt.datetime.now(UTC).isoformat(timespec='seconds')}

    # Shade map: per sun-position bin, ratio of actual to modelled, shrunk towards 1
    sums = {}
    for t_end, kwh, w, el, az in rows:
        pred = predict_hour(m, w, t_end, horizon)
        if pred > 0.05:
            s = sums.setdefault(shade_key(el, az), [0.0, 0.0, 0])
            s[0] += kwh
            s[1] += pred
            s[2] += 1
    for key, (act, pred, n) in sums.items():
        m['shade'][key] = round((act + SHADE_PRIOR * pred / n) / (pred + SHADE_PRIOR * pred / n), 3)

    if verbose:
        total = sum(p['a'] for p in m['planes'])
        print(f"fit: pitch {tilt}°; " + ', '.join(
            f"group facing {p['azimuth']}° ({100 * p['a'] / total:.0f}% of panels)" for p in m['planes'])
            + f"; hourly RMSE before shade map {m['rmse_kwh']} kWh; {len(m['shade'])} shade bins")
    return m


def fit_battery(gen):
    """Battery capacity (kWh), max charge rate (kWh/h) and lowest SoC, from hourly readings."""
    pairs = []
    charge = []
    for t_end, (kwh, _, _, use, soc) in gen.items():
        prev = gen.get(t_end - dt.timedelta(hours=1))
        if not prev:
            continue
        net = kwh - use
        if 20 < prev[4] < 90 and 20 < soc < 90 and abs(net) > 0.3:
            pairs.append((net, soc - prev[4]))
        if prev[4] < 85 and net > 0:
            charge.append(net)
    # SoC change (%) = net energy / capacity * 100, least squares through the origin
    k = sum(n * d for n, d in pairs) / sum(n * n for n, _ in pairs)
    charge.sort()
    return {'capacity_kwh': round(100 / k, 1),
            'max_charge_kwh': round(charge[int(len(charge) * 0.98)], 2),
            'min_soc': min(v[4] for v in gen.values() if v[4] > 0),
            'pairs': len(pairs)}


def load_profile(gen, day, days=28):
    """Average house use (kWh) by local hour over the `days` days before `day`."""
    sums, counts = [0.0] * 24, [0] * 24
    start = day - dt.timedelta(days=days)
    for t_end, v in gen.items():
        local = t_end.astimezone(LOCAL_TZ) - dt.timedelta(hours=1)
        if start <= local.date() < day:
            sums[local.hour] += v[3]
            counts[local.hour] += 1
    return [sums[h] / counts[h] if counts[h] else 0.5 for h in range(24)]


def simulate(potential, load, soc, batt):
    """Generation the house and battery can actually absorb, hour by hour.

    potential, load: kWh per hour. With no export, once the battery is full the
    inverter only produces what the house uses. Returns (actual list, final SoC)."""
    cap = batt['capacity_kwh']
    actual = []
    for pot, use in zip(potential, load):
        room = max(0.0, (100 - soc) / 100 * cap)
        g = min(pot, use + min(batt['max_charge_kwh'], room))
        soc = max(batt['min_soc'], min(100.0, soc + (g - use) / cap * 100))
        actual.append(g)
    return actual, soc


# --- Evaluate -------------------------------------------------------------------

def day_hours(day):
    """UTC end-times of the 24 hours of a local day."""
    start = dt.datetime(day.year, day.month, day.day, tzinfo=LOCAL_TZ).astimezone(UTC)
    return [start + dt.timedelta(hours=h + 1) for h in range(24)]


def evaluate():
    m = fit(TEST_FROM)
    gen = load_generation()
    batt = fit_battery({t: v for t, v in gen.items() if t.date() < TEST_FROM})
    horizon = load_horizon()
    print(f"battery: {batt}")
    print(f"\nBack-test on days from {TEST_FROM} (everything fitted only on earlier data)\n")

    days = sorted({(t - dt.timedelta(hours=1)).astimezone(LOCAL_TZ).date() for t in gen})
    days = [d for d in days if d >= TEST_FROM and all(t in gen for t in day_hours(d))]
    for source, label in [('archive', 'reanalysis radiation (what actually happened)'),
                          ('satellite', 'satellite radiation (only from Feb 2026)'),
                          ('sameday', 'same-day forecast (as known this morning)'),
                          ('dayahead', 'day-ahead forecast (as known the day before)')]:
        weather = load_weather(source)
        clean, every = [], []
        for day in days:
            hours = day_hours(day)
            if not all(t in weather for t in hours):
                continue
            actual = [gen[t][0] for t in hours]
            potential = [predict_hour(m, weather[t], t, horizon) for t in hours]
            soc0 = gen.get(hours[0] - dt.timedelta(hours=1), gen[hours[0]])[4]
            sim, _ = simulate(potential, load_profile(gen, day), soc0, batt)
            every.append((sum(actual), sum(sim)))
            if not any(gen[t][2] for t in hours if hour_sun(t)[0] > 2):
                clean.append((sum(actual), sum(potential)))
        if not every:
            print(f"{label}: no test days")
            continue
        print(f"{label}:")
        for name, pairs in [('potential vs unthrottled days', clean),
                            ('expected actual vs all days  ', every)]:
            if pairs:
                mae = sum(abs(p - a) for a, p in pairs) / len(pairs)
                bias = sum(p - a for a, p in pairs) / len(pairs)
                mean = sum(a for a, _ in pairs) / len(pairs)
                print(f"  {name}: {len(pairs):3d} days, mean {mean:4.1f} kWh/day, "
                      f"MAE {mae:4.1f} kWh ({100 * mae / mean:3.0f}%), bias {bias:+.1f}")

    # Baseline to beat: tomorrow will be like today
    pairs = [(sum(gen[t][0] for t in day_hours(d)),
              sum(gen[t][0] for t in day_hours(d - dt.timedelta(days=1))))
             for d in days if all(t in gen for t in day_hours(d - dt.timedelta(days=1)))]
    mae = sum(abs(p - a) for a, p in pairs) / len(pairs)
    print(f"baseline 'same as yesterday': {len(pairs)} days, MAE {mae:.1f} kWh")


# --- Forecast ---------------------------------------------------------------------

def forecast():
    """Hourly potential and expected generation for today and tomorrow.

    Run collect.py --only solarman first so today's readings are fresh."""
    if not os.path.exists(MODEL_FILE):
        sys.exit("forecast: run 'model.py fit' first")
    with open(MODEL_FILE) as f:
        m = json.load(f)
    gen = load_generation()
    horizon = load_horizon()
    vars_ = ['shortwave_radiation', 'direct_normal_irradiance', 'diffuse_radiation',
             'cloud_cover', 'temperature_2m']
    url = 'https://api.open-meteo.com/v1/forecast?' + urllib.parse.urlencode({
        'latitude': LAT, 'longitude': LON, 'hourly': ','.join(vars_),
        'past_days': 1, 'forecast_days': 3, 'timezone': 'GMT'})
    with urllib.request.urlopen(url, timeout=60) as r:
        weather = parse_open_meteo(json.loads(r.read()))

    now = dt.datetime.now(UTC)
    today = now.astimezone(LOCAL_TZ).date()
    load = load_profile(gen, today)
    # Start from the latest battery reading
    last = max(t for t in gen if t <= now + dt.timedelta(hours=1))
    soc = gen[last][4]

    out = {'generated_at': now.astimezone(LOCAL_TZ).isoformat(timespec='seconds'),
           'battery': m['battery'], 'start_soc': soc, 'days': []}
    for day in (today, today + dt.timedelta(days=1)):
        hours = []
        for t_end in day_hours(day):
            w = weather.get(t_end, {})
            local_hour = (t_end - dt.timedelta(hours=1)).astimezone(LOCAL_TZ).hour
            pot = predict_hour(m, w, t_end, horizon) if w else 0.0
            if t_end <= last:
                # Already happened: report what was measured
                exp = gen[t_end][0] if t_end in gen else None
                hour_soc = gen[t_end][4] if t_end in gen else None
            else:
                (exp,), soc = simulate([pot], [load[local_hour]], soc, m['battery'])
                hour_soc = soc
            hours.append({'hour': f"{local_hour:02d}:00", 'potential_kwh': round(pot, 2),
                          'expected_kwh': None if exp is None else round(exp, 2),
                          'soc': None if hour_soc is None else round(hour_soc),
                          'measured': t_end <= last, 'cloud_cover': w.get('cloud_cover')})
        daylight = [h for h in hours if h['potential_kwh'] > 0 or h['expected_kwh']]
        # The hour by the end of which the battery is full: from then on generation is throttled
        full = next((h for h in daylight if h['soc'] is not None and h['soc'] >= CURTAIL_SOC), None)
        out['days'].append({
            'date': str(day),
            'potential_kwh': round(sum(h['potential_kwh'] for h in hours), 1),
            'expected_kwh': round(sum(h['expected_kwh'] or 0 for h in hours), 1),
            'measured_kwh': round(sum(h['expected_kwh'] or 0 for h in hours if h['measured']), 1),
            'battery_full_by': None if full is None else f"{int(full['hour'][:2]) + 1:02d}:00",
            'hours': daylight})
    tmp = FORECAST_FILE + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(out, f, indent=1)
    os.replace(tmp, FORECAST_FILE)
    for d in out['days']:
        print(f"{d['date']}: potential {d['potential_kwh']} kWh, expected {d['expected_kwh']} kWh")

    today, tomorrow = out['days']
    compact = {'ts': int(now.timestamp()), 'at': now.astimezone(LOCAL_TZ).strftime('%H:%M'),
               'today': {'exp': today['expected_kwh'], 'pot': today['potential_kwh'],
                         'made': today['measured_kwh'], 'full': today['battery_full_by']},
               'tomorrow': {'exp': tomorrow['expected_kwh'], 'pot': tomorrow['potential_kwh'],
                            'full': tomorrow['battery_full_by']}}
    try:
        mqtt_publish(MQTT_TOPIC, json.dumps(compact, separators=(',', ':')))
    except (OSError, RuntimeError) as e:
        # The forecast file is written; the displays just keep the previous message
        print(f"forecast: MQTT publish failed: {e}", file=sys.stderr)


# --- MQTT -------------------------------------------------------------------------

def mqtt_publish(topic, payload):
    """Publish one retained QoS 0 message (MQTT 3.1.1) using the login in vars.php.

    Plain sockets rather than mosquitto_pub, so the password never appears on a command line."""
    from collect import php_defines
    cfg = php_defines('MQTT_')

    def string(s):
        b = s.encode()
        return struct.pack('!H', len(b)) + b

    def packet(kind, body):
        n, length = len(body), b''
        while True:
            n, digit = divmod(n, 128)
            length += bytes([digit | (128 if n else 0)])
            if not n:
                return bytes([kind]) + length + body

    connect = (string('MQTT') + bytes([4, 0xC2]) + struct.pack('!H', 30)   # v3.1.1, user+pass, clean
               + string('solar-forecast') + string(cfg['MQTT_USER']) + string(cfg['MQTT_PASS']))
    with socket.create_connection((cfg['MQTT_HOST'], int(cfg['MQTT_PORT'])), timeout=10) as sock:
        sock.sendall(packet(0x10, connect))
        connack = sock.recv(4)
        if len(connack) < 4 or connack[0] != 0x20 or connack[3] != 0:
            raise RuntimeError(f"broker refused connection (CONNACK {connack.hex()})")
        sock.sendall(packet(0x31, string(topic) + payload.encode()))   # PUBLISH, retain
        sock.sendall(packet(0xE0, b''))                                # DISCONNECT
    print(f"forecast: published {len(payload)} bytes to {topic}")


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'fit':
        # Production model uses all data up to today
        model = fit(dt.date.today() + dt.timedelta(days=1))
        model['battery'] = fit_battery(load_generation())
        with open(MODEL_FILE, 'w') as f:
            json.dump(model, f, indent=1)
    elif cmd == 'evaluate':
        evaluate()
    elif cmd == 'forecast':
        forecast()
    else:
        sys.exit(__doc__)
