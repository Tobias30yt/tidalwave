#!/usr/bin/env python3
"""Initial setup only. Secrets remain in root-only files, never standard output."""
import base64,json,pathlib,urllib.request,sys,time
root=pathlib.Path('/opt/tidalwave-mail')
if (root/'bootstrap-result.json').exists():
    raise SystemExit('Bootstrap result already exists; refusing to overwrite recovery credentials.')
credential=(root/'bootstrap.env').read_text().strip().split('=',1)[1]
def call(method,args):
    body=json.dumps({'using':['urn:ietf:params:jmap:core','urn:stalwart:jmap'],'methodCalls':[[method,args,'c1']]}).encode()
    req=urllib.request.Request('http://127.0.0.1:18080/jmap/',body,headers={'Content-Type':'application/json','Authorization':'Basic '+base64.b64encode(credential.encode()).decode()})
    return json.load(urllib.request.urlopen(req,timeout=30))
domain=sys.argv[1] if len(sys.argv)>1 else 'tidlawaveenterprises.duckdns.org'
r=call('x:Bootstrap/set',{'update':{'singleton':{'defaultHostname':domain,'defaultDomain':domain,'requestTlsCertificate':False,'generateDkimKeys':True}}})
p=root/'bootstrap-result.json';p.write_text(json.dumps(r));p.chmod(0o600)
for name,result,_ in r.get('methodResponses',[]):
    print(name, 'success' if result.get('updated') else 'failed', 'response stored privately')
