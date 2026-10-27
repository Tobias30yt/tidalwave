#!/usr/bin/env python3
"""Create the website management identity once; never overwrite private config."""
import importlib.util,json,pathlib,secrets,os,grp
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('manage',here/'mail-manage.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
p=pathlib.Path('/var/www/tidalwave/config/mail.local.php')
if p.exists():raise SystemExit('Mail configuration already exists; no changes made.')
domain=m.call('x:Domain/get',{})['list'][0]
if any(a['name']=='tidalwave-management' for a in m.call('x:Account/get',{})['list']):
    raise SystemExit('Management account exists without local config. Recover its private configuration before continuing.')
password=secrets.token_urlsafe(40)
# Stalwart can only grant permissions held by the creating identity. Normal user
# rights are therefore inherited for provisioning, but impersonate/fetchAnyBlob are not.
account={'@type':'User','name':'tidalwave-management','domainId':domain['id'],'credentials':{'0':{'@type':'Password','secret':password}},'roles':{'@type':'User'},'permissions':{'@type':'Merge','enabledPermissions':{k:True for k in ['authenticate','sysAccountGet','sysAccountCreate','sysAccountUpdate','sysAccountDestroy','sysAccountQuery','sysDomainGet']},'disabledPermissions':{}},'quotas':{'maxDiskQuota':1048576}}
m.call('x:Account/set',{'create':{'service':account}})
config={'enabled':True,'url':'http://127.0.0.1:18080','domain':domain['name'],'domain_id':domain['id'],'management_user':'tidalwave-management@'+domain['name'],'management_password':password,'external_delivery':False}
with p.open('x') as f:f.write('<?php\nreturn json_decode('+repr(json.dumps(config))+', true);\n')
p.chmod(0o640);os.chown(p,0,grp.getgrnam('tidalwave').gr_gid)
print('Restricted mailbox management identity configured.')
