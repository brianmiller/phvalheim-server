"""Check local links, images, video and fragment targets in the built documentation."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlparse, unquote
import sys
root=Path(sys.argv[1] if len(sys.argv)>1 else '.site').resolve()
class Page(HTMLParser):
    def __init__(self,text):
        super().__init__();self.links=[];self.ids=set();self.feed(text)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if 'id' in a:self.ids.add(a['id'])
        if tag=='a' and a.get('name'):self.ids.add(a['name'])
        for attr in ('href','src','poster'):
            if a.get(attr):self.links.append(a[attr])
pages={p:Page(p.read_text()) for p in root.rglob('*.html')}
errors=[];checked=0
for path,page in pages.items():
    for link in page.links:
        u=urlparse(link)
        if u.scheme or u.netloc:continue
        relative=unquote(u.path)
        target=(path.parent/relative).resolve() if relative else path
        if relative.startswith('/'):
            prefix='/phvalheim-server/'
            if not relative.startswith(prefix):errors.append((path,link,'outside site prefix'));continue
            target=root/relative[len(prefix):]
        if target.is_dir():target=target/'index.html'
        if not target.is_file():errors.append((path,link,'missing file'));continue
        if u.fragment and target in pages and unquote(u.fragment) not in pages[target].ids:
            errors.append((path,link,'missing anchor'))
        checked+=1
for path,link,reason in errors:print(f'{path.relative_to(root)}: {reason}: {link}')
print(f'{len(pages)} HTML pages, {checked} local references, {len(errors)} errors')
raise SystemExit(bool(errors))
