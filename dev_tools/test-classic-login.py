#!/usr/bin/env python3
"""Render Classic's real PHP form; verify safe, unchanged Steam handoff without extensions."""
import json, subprocess
from pathlib import Path
from html.parser import HTMLParser
class Form(HTMLParser):
    def __init__(self, html):
        super().__init__(); self.elements=[]; self.feed(html)
    def handle_starttag(self, tag, attrs): self.elements.append((tag,dict(attrs)))
    def matching(self, tag=None, **attrs):
        return [a for t,a in self.elements if (tag is None or tag==t) and all(a.get(k)==v for k,v in attrs.items())]
source=Path(__file__).resolve().parents[1]/'container/nginx/www/includes/classicLogin.php'
for origin in ['https://world.example','https://world.example/" onmouseover="bad']:
    for auth in ['', 'failed']:
        html=subprocess.check_output(['php','-r', '$steamRealm=$argv[1]; $_GET["auth"]=$argv[2]; require $argv[3];',origin,auth,str(source)],text=True)
        page=Form(html)
        form=page.matching('form',id='authenticated_form');assert len(form)==1
        assert form[0]['method']=='post' and form[0]['action']=='https://steamcommunity.com/openid/login'
        expected={'openid.realm':origin,'openid.return_to':origin+'/authenticated.php','openid.mode':'checkid_setup','openid.ns':'http://specs.openid.net/auth/2.0','openid.identity':'http://specs.openid.net/auth/2.0/identifier_select','openid.claimed_id':'http://specs.openid.net/auth/2.0/identifier_select'}
        for name,value in expected.items():
            inputs=page.matching('input',name=name);assert len(inputs)==1 and inputs[0]['value']==value,name
        assert all('onmouseover' not in attrs for _,attrs in page.elements),'origin escaping'
        assert len(page.matching(id='steamAuthFailed',role='alert'))==int(auth=='failed')
        assert len(page.matching('input',type='image',alt='Sign in through Steam'))==1
        assert len(page.matching('ul',**{'class':'switcher'}))==1
        assert len(page.matching(id='login'))==len(page.matching(id='signup'))==1
print('PASS: original Classic form, Steam POST fields, origin escaping, retry state and accessible image button')
