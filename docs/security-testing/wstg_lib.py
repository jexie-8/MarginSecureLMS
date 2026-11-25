"""Tiny HTTP client and result log for the security tests (standard library only)."""
import http.client
import json
import os
import re
import urllib.parse
import uuid

TARGETS = {
    # 127.0.0.1 rather than "localhost": Windows tries IPv6 first and waits ~2 s per request.
    'waf': ('127.0.0.1', 8088),      # through the ModSecurity firewall (what the tunnel reaches)
    'direct': ('127.0.0.1', 8089),   # the application alone (docker-compose.test.yml)
}
ORIGIN = 'http://localhost:8088'
BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) WSTG-test'


class Resp:
    def __init__(self, status, headers, body, set_cookies, elapsed):
        self.status = status
        self.headers = headers          # lower-case name -> last value
        self.body = body
        self.set_cookies = set_cookies  # raw Set-Cookie strings
        self.elapsed = elapsed

    @property
    def text(self):
        return self.body.decode('utf-8', 'replace')

    def json(self):
        try:
            return json.loads(self.body)
        except Exception:
            return None


class Client:
    def __init__(self, target='direct', ua=BROWSER_UA, browser=True, origin=ORIGIN):
        self.host, self.port = TARGETS[target]
        self.ua = ua
        self.browser = browser
        self.origin = origin
        self.cookies = {}
        self.token = None          # bearer token (mobile flow)
        self.last = None

    # -- low level -----------------------------------------------------------
    def request(self, method, path, json_body=None, headers=None, raw=None, ctype=None,
                files=None, fields=None, xsrf=True, timeout=40, cookies=True, extra_ua=None):
        import time
        # Send the name a browser would use. A bare IP in Host trips CRS rule
        # 920350, which would add noise to every request through the firewall.
        h = {'Host': f'localhost:{self.port}', 'Accept': 'application/json', 'User-Agent': extra_ua or self.ua}
        if self.browser:
            h['Origin'] = self.origin
            h['Sec-Fetch-Site'] = 'same-origin'
        if cookies and self.cookies:
            h['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        if xsrf and 'XSRF-TOKEN' in self.cookies and method not in ('GET', 'HEAD', 'OPTIONS'):
            h['X-XSRF-TOKEN'] = urllib.parse.unquote(self.cookies['XSRF-TOKEN'])
        if self.token:
            h['Authorization'] = f'Bearer {self.token}'
        body = None
        if json_body is not None:
            body = json.dumps(json_body).encode()
            h['Content-Type'] = 'application/json'
        elif raw is not None:
            body = raw if isinstance(raw, bytes) else raw.encode()
            h['Content-Type'] = ctype or 'application/json'
        elif files is not None or fields is not None:
            boundary = '----wstg' + uuid.uuid4().hex
            parts = []
            for k, v in (fields or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fname, ctype_, data) in (files or {}).items():
                parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\n'
                              f'Content-Type: {ctype_}\r\n\r\n').encode() + data + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            h['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        if headers:
            h.update({k: v for k, v in headers.items() if v is not None})
            for k, v in headers.items():
                if v is None:
                    h.pop(k, None)
        conn = http.client.HTTPConnection(self.host, self.port, timeout=timeout)
        t0 = time.time()
        try:
            conn.request(method, path, body=body, headers=h)
            r = conn.getresponse()
            data = r.read()
            elapsed = time.time() - t0
            hdrs = {k.lower(): v for k, v in r.getheaders()}
            sc = [v for k, v in r.getheaders() if k.lower() == 'set-cookie']
        finally:
            conn.close()
        for line in sc:
            name, _, rest = line.partition('=')
            value = rest.split(';', 1)[0]
            if 'expires=Thu, 01 Jan 1970' in line or value in ('', 'deleted'):
                self.cookies.pop(name, None)
            else:
                self.cookies[name] = value
        self.last = Resp(r.status, hdrs, data, sc, elapsed)
        return self.last

    def get(self, path, **kw):
        return self.request('GET', path, **kw)

    def post(self, path, body=None, **kw):
        return self.request('POST', path, json_body=body if body is not None else {}, **kw)

    def put(self, path, body=None, **kw):
        return self.request('PUT', path, json_body=body if body is not None else {}, **kw)

    def delete(self, path, **kw):
        return self.request('DELETE', path, **kw)

    # -- sessions ---------------------------------------------------------------
    def csrf(self):
        return self.request('GET', '/sanctum/csrf-cookie')

    def login(self, email, password):
        self.csrf()
        return self.post('/api/v1/login', {'email': email, 'password': password})

    def api(self, path):
        return '/api/v1' + path


def env_value(name):
    p = os.path.join(os.path.dirname(__file__), '..', '..', 'deploy', 'local', '.env')
    for line in open(p, encoding='utf-8'):
        if line.startswith(name + '='):
            return line.split('=', 1)[1].strip()
    raise KeyError(name)


class Report:
    def __init__(self):
        self.rows = []

    def add(self, wstg, title, status, detail='', severity=''):
        """status: PASS (control worked), FAIL (finding), INFO (note or limit)."""
        self.rows.append({'wstg': wstg, 'title': title, 'status': status, 'detail': detail, 'severity': severity})
        mark = {'PASS': 'ok  ', 'FAIL': 'FAIL', 'INFO': 'info'}[status]
        print(f'[{mark}] {wstg:<14} {title}' + (f'  -> {detail}' if detail and status != 'PASS' else ''))

    def check(self, wstg, title, condition, detail_ok='', detail_bad='', severity='medium'):
        if condition:
            self.add(wstg, title, 'PASS', detail_ok)
        else:
            self.add(wstg, title, 'FAIL', detail_bad, severity)
        return condition
