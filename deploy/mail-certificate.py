#!/usr/bin/env python3
"""Install/renew the existing Nginx certificate in Stalwart without exposing keys."""
import importlib.util,pathlib
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('manage',here/'mail-manage.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
domain='tidlawaveenterprises.duckdns.org'
cert=pathlib.Path('/etc/letsencrypt/live')/domain
fields={'certificate':{'@type':'Text','value':(cert/'fullchain.pem').read_text()},'privateKey':{'@type':'Text','secret':(cert/'privkey.pem').read_text()}}
existing=[c for c in m.call('x:Certificate/get',{})['list'] if domain in c.get('subjectAlternativeNames',{})]
if existing:m.call('x:Certificate/set',{'update':{existing[0]['id']:fields}})
else:m.call('x:Certificate/set',{'create':{'website':fields}})
m.call('x:Action/set',{'create':{'reload':{'@type':'ReloadTlsCertificates'}}})
print('Stalwart TLS certificate synchronized.')
