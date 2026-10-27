"""Integration checks against a running PHP server. Python standard library only."""
import hashlib
import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urljoin, urlsplit, unquote
from urllib.request import Request, urlopen

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
ROOT = Path(__file__).resolve().parents[1]
ROUTES = ['/', '/about', '/bots', '/projects', '/forum', '/api', '/contact', '/account', '/legal',
          '/legal/privacy', '/legal/terms', '/legal/notice',
          '/legal/synatra/privacy', '/legal/synatra/terms']
AUTH_REQUIRED_ROUTES = {'/mail', '/admin/api'}

class Page(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.links, self.assets, self.ids, self.headings, self.mains = [], [], [], 0, 0
        self.feed(html)
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'id' in attrs: self.ids.append(attrs['id'])
        if tag == 'h1': self.headings += 1
        if tag == 'main': self.mains += 1
        if tag == 'a' and 'href' in attrs: self.links.append(attrs['href'])
        if tag in ('img', 'script') and 'src' in attrs: self.assets.append(attrs['src'])
        if tag == 'link' and 'href' in attrs: self.assets.append(attrs['href'])

def request(path, method='GET'):
    try:
        with urlopen(Request(BASE + path, method=method), timeout=15) as response:
            return response.status, response.headers, response.read()
    except HTTPError as response:
        return response.code, response.headers, response.read()

pages, assets = {}, set()
for route in ROUTES:
    status, headers, raw = request(route)
    assert status == 200, (route, status)
    assert "frame-ancestors 'none'" in headers['Content-Security-Policy']
    assert headers['X-Content-Type-Options'] == 'nosniff'
    assert headers['X-Frame-Options'] == 'DENY'
    if route != '/account': assert not headers.get('Set-Cookie')
    html = raw.decode('utf-8')
    assert 'Warning:' not in html and 'Fatal error:' not in html
    page = Page(html)
    assert page.headings == 1 and page.mains == 1, (route, 'semantic landmarks')
    assert len(page.ids) == len(set(page.ids)), (route, 'duplicate IDs')
    assert all(link and link != '#' for link in page.links), (route, 'empty link')
    pages[route] = page
    assets.update(page.assets)

for route, page in pages.items():
    for link in page.links:
        target = urlsplit(urljoin(BASE + route, link))
        if target.netloc != urlsplit(BASE).netloc: continue
        if re.fullmatch(r'/forum/thread/[0-9]+|/projects/download/[a-f0-9]{32}', target.path):
            assert request(target.path)[0] == 200, ('dynamic public link', link)
            continue
        if target.path in AUTH_REQUIRED_ROUTES:
            assert request(target.path)[0] in (401, 503), ('protected route or unconfigured auth', link)
            continue
        assert target.path in pages, (route, 'unrecognized route', link)
        if target.fragment:
            assert unquote(target.fragment) in pages[target.path].ids, (route, 'broken fragment', link)
for asset in assets:
    assert asset.startswith('/'), ('remote asset', asset)
    assert request(asset)[0] == 200, ('missing asset', asset)
for path in ['/missing', '/config/app.php', '/src/Views/home.php', '/.env',
             '/storage/logs/php-error.log', '/assets/images/authorized/README.md',
             '/api/v1/test', '/%3Cscript%3Ealert(1)%3C/script%3E']:
    status, _, body = request(path)
    assert status == 404, (path, status)
    assert b'<script>alert(1)' not in body
for method in ['POST', 'PUT', 'DELETE', 'PATCH']:
    status, headers, _ = request('/', method)
    assert status == 405 and headers['Allow'] == 'GET, HEAD', method
assert request('/', 'HEAD')[2] == b''

manifest = json.loads((ROOT / 'docs/legal-source/manifest.json').read_text())
for name, count in [('privacy', 17), ('terms', 15)]:
    raw = (ROOT / f'docs/legal-source/{name}.html').read_bytes()
    assert hashlib.sha256(raw).hexdigest() == manifest[name]['sha256']
    original = raw.decode('utf-8-sig')
    local = (ROOT / f'src/Views/legal/{name}.html').read_text(encoding='utf-8')
    pattern = r'<section class="legal-section".*?</section>'
    expected = re.findall(pattern, original, re.S)
    actual = re.findall(pattern, local, re.S)
    assert len(expected) == count and len(actual) == count
    for before, after in zip(expected, actual):
        before = before.replace('href="privacy.html"', 'href="/legal/synatra/privacy"')
        before = before.replace('href="terms.html"', 'href="/legal/synatra/terms"')
        assert before == after, (name, 'legal wording changed')
    assert original.count('TO BE COMPLETED') == local.count('TO BE COMPLETED')
print(f'PASS: {len(pages)} pages, {len(assets)} assets, internal links/fragments, security headers, methods, private paths, and 32 verbatim legal sections.')
