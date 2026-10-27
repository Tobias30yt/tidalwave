#!/usr/bin/env python3
"""Root-only Stalwart maintenance helper. Never prints credentials."""
import base64,json,pathlib,urllib.request,sys
ROOT=pathlib.Path('/opt/tidalwave-mail')
def credentials():
    result=json.loads((ROOT/'bootstrap-result.json').read_text())['methodResponses'][0][1]['updated']['singleton']
    return result['username']+':'+result['secret']
def call(method,args):
    req=urllib.request.Request('http://127.0.0.1:18080/jmap/',json.dumps({'using':['urn:ietf:params:jmap:core','urn:stalwart:jmap'],'methodCalls':[[method,args,'c1']]}).encode(),headers={'Content-Type':'application/json','Authorization':'Basic '+base64.b64encode(credentials().encode()).decode()})
    response=json.load(urllib.request.urlopen(req,timeout=30))
    name,result,_=response['methodResponses'][0]
    if name=='error' or any(result.get(k) for k in ['notCreated','notUpdated','notDestroyed']):
        errors=result.get('notCreated') or result.get('notUpdated') or result.get('notDestroyed') or {'request':result}
        raise RuntimeError(str({k:{p:v.get(p) for p in ['type','description','properties']} for k,v in errors.items()}))
    return result
if __name__=='__main__':
    for kind in ['Domain','Account','Certificate','NetworkListener']:
        try:
            result=call('x:'+kind+'/get',{})
            print(kind,[{k:v for k,v in row.items() if k in ['id','name','emailAddress','@type','hostname']} for row in result.get('list',[])])
        except Exception as e:print(kind,type(e).__name__)
