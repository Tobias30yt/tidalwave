"""Run explicitly as root on a Docker test host; never touches production account/config data."""
import importlib.util
import json
import os
from pathlib import Path
import pwd
import sqlite3
import subprocess
import tempfile
import time
import uuid
import zipfile

ROOT=Path(__file__).parents[1]
spec=importlib.util.spec_from_file_location('worker',ROOT/'runner/worker.py')
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
account=pwd.getpwnam('tidalwave')
with tempfile.TemporaryDirectory(prefix='tw-runner-test-') as tmp:
    root=Path(tmp);root.chmod(0o755)
    shared=root/'shared';shared.mkdir();os.chown(shared,account.pw_uid,account.pw_gid)
    code='require $argv[1]."/src/Services/Auth/bootstrap.php"; require $argv[1]."/src/Services/AdminStore.php"; new AdminStore(["storage"=>$argv[2],"max_bots"=>4]);'
    subprocess.run(['sudo','-u','tidalwave','php','-r',code,str(ROOT),str(shared)],check=True)
    w.SHARED=shared;w.PRIVATE=root/'private'
    runner=w.Runner()
    fixtures={
        'python': ('bot.py', 'import os,time\nassert os.getenv("TOKEN")=="synthetic-secret"\nassert "TOKEN=synthetic-secret" in open(".env").read()\nimport discord\nprint("PYTHON_READY synthetic-secret",flush=True)\nwhile True: time.sleep(1)\n', 'python bot.py', 'pip install -q --disable-pip-version-check discord.py==2.7.1\nprintf installed > installed.txt'),
        'node': ('index.js', 'const fs=require("fs");if(process.env.TOKEN!=="synthetic-secret"||!fs.readFileSync(".env","utf8").includes("synthetic-secret"))process.exit(1);console.log("NODE_READY synthetic-secret");setInterval(()=>{},1000);', 'node index.js', 'node --version\nnpm --version\nprintf installed > installed.txt'),
        'java': ('Main.java', 'public class Main { public static void main(String[] args) throws Exception { if (!"synthetic-secret".equals(System.getenv("TOKEN"))) throw new Exception(); System.out.println("JAVA_READY synthetic-secret"); while(true) Thread.sleep(1000); } }', 'java Main', 'javac Main.java\nprintf installed > installed.txt'),
    }
    ids=[]
    try:
        for runtime,(filename,source,start,install) in fixtures.items():
            bot_id=uuid.uuid4().hex;source_id=uuid.uuid4().hex;ids.append(bot_id)
            with zipfile.ZipFile(shared/'uploads'/f'{source_id}.zip','w') as z:z.writestr('project/'+filename,source)
            runner.db.execute('INSERT INTO bots(id,name,runtime,start_command,install_command,environment,source,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)',(bot_id,'Synthetic '+runtime,runtime,start,install,'TOKEN=synthetic-secret',source_id,int(time.time()),int(time.time())))
            bot=dict(runner.db.execute('SELECT * FROM bots WHERE id=?',(bot_id,)).fetchone())
            runner.deploy(bot)
            assert runner.db.execute('SELECT published_at FROM bots WHERE id=?',(bot_id,)).fetchone()[0] is not None
            container=w.inspect('tw-bot-'+bot_id)
            assert container['State']['Running']
            assert container['Config']['User']=='1000:1000'
            assert container['HostConfig']['Memory']==512*1024*1024 and not container['Mounts']
            runner.refresh()
            logs=runner.db.execute('SELECT logs FROM bots WHERE id=?',(bot_id,)).fetchone()[0]
            assert runtime.upper()+'_READY' in logs and 'synthetic-secret' not in logs and '[redacted]' in logs
            runner.job({'bot_id':bot_id,'action':'stop'})
            assert not w.inspect('tw-bot-'+bot_id)['State']['Running']
            runner.job({'bot_id':bot_id,'action':'start'})
            assert w.inspect('tw-bot-'+bot_id)['State']['Running']
            if runtime=='python':
                original_image=container['Image']
                runner.deploy(bot)
                # Dependencies must remain available on cached redeploy.
                assert w.inspect('tw-bot-'+bot_id)['State']['Running']
                assert w.inspect('tw-bot-'+bot_id)['Image']==original_image
                bad=dict(bot);bad['install_command']='exit 17'
                try: runner.deploy(bad)
                except RuntimeError: pass
                else: raise AssertionError('Failed install accepted')
                assert w.inspect('tw-bot-'+bot_id)['State']['Running']
                bad=dict(bot);bad['start_command']='python missing.py'
                try: runner.deploy(bad)
                except RuntimeError: pass
                else: raise AssertionError('Failed startup accepted')
                assert w.inspect('tw-bot-'+bot_id)['State']['Running']
            print('PASS: '+runtime+' install, unprivileged runtime, private environment, redacted logs, stop/start',flush=True)
        # The bot network must not be able to reach cloud metadata or the host bridge.
        probe=w.docker('run','--rm','--network',w.NETWORK,w.BASE_IMAGE,'/opt/venv/bin/python','-c',
            'import socket\nfor host in ["169.254.169.254","172.29.80.1"]:\n try: socket.create_connection((host,80),2)\n except OSError: continue\n raise SystemExit("Private network reachable")\nprint("PASS: metadata and host networking blocked")')
        print(probe.stdout.strip())
    finally:
        for bot_id in ids:
            runner.job({'bot_id':bot_id,'action':'delete'})
        runner.db.close()
