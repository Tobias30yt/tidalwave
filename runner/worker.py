"""Fixed-function job runner. No uploaded command is ever executed by the host shell."""
import hashlib
import io
import json
import os
from pathlib import Path
import pwd
import re
import selectors
import shlex
import shutil
import sqlite3
import stat
import subprocess
import tempfile
import time
import zipfile

SHARED = Path('/var/www/tidalwave/storage/admin')
PRIVATE = Path('/var/lib/tidalwave-runner')
BASE_IMAGE = 'tidalwave-runtime:v1'
NETWORK = 'tidalwave-bots'
ID = re.compile(r'[a-f0-9]{32}')
RESERVED = {'PATH','HOME','LD_PRELOAD','LD_LIBRARY_PATH','PYTHONPATH','PYTHONHOME','NODE_OPTIONS','JAVA_TOOL_OPTIONS','_JAVA_OPTIONS'}

def identifier(value):
    if not isinstance(value, str) or not ID.fullmatch(value):
        raise ValueError('Invalid internal identifier')
    return value

def environment(text):
    if not isinstance(text, str) or len(text.encode()) > 16384:
        raise ValueError('Environment exceeds 16 KB')
    result = {}
    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        key, sep, value = line.partition('=')
        if not sep or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', key) or key in RESERVED or key in result or any(ord(c)<32 or ord(c)==127 for c in value):
            raise ValueError('Invalid or reserved environment key/value')
        result[key] = value
    return result

def start_args(runtime, command):
    if len(command)>1000 or any(ord(c)<32 for c in command):
        raise ValueError('Invalid start command')
    args = shlex.split(command)
    allowed = {'node': {'node':'/usr/local/bin/node'}, 'python': {'python':'/opt/venv/bin/python','python3':'/opt/venv/bin/python3'}, 'java': {'java':'/usr/bin/java'}}
    if len(args)<2 or args[0] not in allowed.get(runtime,{}) or any(a in {';','|','||','&','&&','>','<','>>'} for a in args):
        raise ValueError('Start command must use the selected node, python, or java runtime without shell operators')
    args[0] = allowed[runtime][args[0]]
    return args

def extract_source(raw, destination):
    if len(raw)>25*1024*1024:
        raise ValueError('Source archive exceeds 25 MB')
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        entries = archive.infolist()
        if not 1<=len(entries)<=2000 or sum(e.file_size for e in entries)>100*1024*1024:
            raise ValueError('Archive exceeds extraction limits')
        seen = set()
        for entry in entries:
            name = entry.filename
            parts = name.rstrip('/').split('/')
            kind = stat.S_IFMT(entry.external_attr >> 16)
            if not name or len(name)>240 or name.startswith('/') or '\\' in name or ':' in name or any(ord(c)<32 or ord(c)==127 for c in name) or any(p in {'','.','..','.git','.env'} for p in parts) or name in seen or kind not in {0,stat.S_IFREG,stat.S_IFDIR} or entry.flag_bits & 1:
                raise ValueError('Unsafe archive path, secret file, link, or encrypted entry')
            seen.add(name)
        files = [e for e in entries if not e.is_dir()]
        if not files:
            raise ValueError('Archive contains no source files')
        # Strip a single enclosing folder, but never silently discard top-level files.
        first = files[0].filename.split('/')[0]
        strip = all(e.filename.startswith(first+'/') for e in files)
        actual = 0
        for entry in files:
            relative = entry.filename[len(first)+1:] if strip else entry.filename
            target = destination / relative
            target.parent.mkdir(parents=True,exist_ok=True)
            with archive.open(entry) as source, target.open('xb') as output:
                while chunk := source.read(65536):
                    actual += len(chunk)
                    if actual>100*1024*1024:
                        raise ValueError('Expanded source exceeds limit')
                    output.write(chunk)
            target.chmod(0o644)

def docker(*args, check=True, timeout=60):
    result = subprocess.run(['/usr/bin/docker',*args],capture_output=True,text=True,timeout=timeout)
    if check and result.returncode:
        # Commands or Docker diagnostics can contain secrets; never include them in exceptions.
        raise RuntimeError('Container operation failed. Check the installation/runtime logs and command.')
    return result

def inspect(name):
    result = docker('container','inspect',name,check=False)
    return json.loads(result.stdout)[0] if result.returncode==0 else None

class Runner:
    def __init__(self):
        PRIVATE.mkdir(mode=0o700,parents=True,exist_ok=True)
        os.umask(0o077)
        # SQLite creates its WAL/SHM as the website user, keeping the shared database private.
        original = os.geteuid()
        if original==0:
            os.seteuid(pwd.getpwnam('tidalwave').pw_uid)
        try:
            self.db = sqlite3.connect(SHARED/'panel.sqlite',timeout=5,isolation_level=None)
            self.db.row_factory = sqlite3.Row
            self.db.execute('PRAGMA trusted_schema=OFF')
            self.db.execute('PRAGMA journal_mode=WAL')
            self.heartbeat()
        finally:
            if original==0:
                os.seteuid(original)

    def heartbeat(self):
        self.db.execute('INSERT INTO health(id,heartbeat) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET heartbeat=excluded.heartbeat',(int(time.time()),))

    def log(self, bot, message, status=None):
        message = str(message)[-32768:]
        for value in sorted(environment(bot['environment']).values(), key=len, reverse=True):
            if value:
                message = message.replace(value,'[redacted]')
        # Also suppress common credential assignments, including installation command output.
        message = re.sub(r'(?im)((?:token|password|secret|api_key)\s*[=:]\s*)\S+',r'\1[redacted]',message)
        if status:
            self.db.execute('UPDATE bots SET logs=?,status=? WHERE id=?',(message,status,bot['id']))
        else:
            self.db.execute('UPDATE bots SET logs=? WHERE id=?',(message,bot['id']))

    def owned(self, name, bot_id):
        data = inspect(name)
        if data and data['Config'].get('Labels',{}).get('tw.managed') != bot_id:
            raise ValueError('Container ownership check failed')
        return data

    def remove(self, name, bot_id):
        if self.owned(name, bot_id):
            docker('rm','-f',name)

    def stream_setup(self, bot, name):
        process = subprocess.Popen(['/usr/bin/docker','start','-a',name],stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
        selector = selectors.DefaultSelector()
        selector.register(process.stdout,selectors.EVENT_READ)
        output = 'Installing dependencies…\n'
        deadline = time.monotonic()+900
        try:
            while process.poll() is None or selector.get_map():
                if time.monotonic()>deadline:
                    raise RuntimeError('Installation exceeded the 15-minute limit')
                if shutil.disk_usage(PRIVATE).free < 3*1024**3:
                    raise RuntimeError('Installation stopped: server disk reserve reached')
                for key,_ in selector.select(timeout=1):
                    chunk = os.read(key.fd,8192)
                    if not chunk:
                        selector.unregister(key.fileobj)
                    else:
                        output = (output+chunk.decode(errors='replace'))[-32768:]
                self.heartbeat()
                self.log(bot, output, 'installing')
            state = inspect(name)['State']
            if state['ExitCode'] != 0:
                raise RuntimeError('Installation failed (exit '+str(state['ExitCode'])+'). Fix the commands and deploy again.')
        finally:
            selector.close()
            if process.poll() is None:
                docker('stop','-t','3',name,check=False)
                process.terminate()
            process.wait(timeout=15)
            process.stdout.close()

    def deploy(self, bot):
        bot_id = identifier(bot['id'])
        args = start_args(bot['runtime'],bot['start_command'])
        env = environment(bot['environment'])
        install = bot['install_command']
        if len(install)>8000 or '\0' in install:
            raise ValueError('Invalid installation command')
        source_id = identifier(bot['source'])
        descriptor = os.open(SHARED/'uploads'/f'{source_id}.zip',os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(descriptor,'rb') as source:
            if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):
                raise ValueError('Source must be a regular file')
            raw = source.read(25*1024*1024+1)
        digest = hashlib.sha256(raw+install.encode()+BASE_IMAGE.encode()).hexdigest()[:20]
        image = f'tidalwave-managed:{bot_id}-{digest}'
        name = 'tw-bot-'+bot_id
        setup = 'tw-setup-'+bot_id
        staged = 'tw-staged-'+bot_id
        with tempfile.TemporaryDirectory(prefix='deploy-',dir=PRIVATE) as tmp:
            temp = Path(tmp)
            if docker('image','inspect',image,check=False).returncode:
                src = temp/'source'; src.mkdir()
                extract_source(raw,src)
                script = temp/'install.sh'
                script.write_text('#!/bin/sh\nset -eu\ncd /app\n'+install+'\n')
                self.remove(setup,bot_id)
                docker('create','--name',setup,'--label','tw.managed='+bot_id,'--network',NETWORK,
                       '--memory','1g','--memory-swap','1g','--cpus','1','--pids-limit','256',
                       '--log-opt','max-size=2m','--log-opt','max-file=2',BASE_IMAGE,'/bin/sh','/tmp/tw-install.sh')
                try:
                    docker('cp',str(src)+'/.',setup+':/app')
                    docker('cp',str(script),setup+':/tmp/tw-install.sh')
                    self.stream_setup(bot,setup)
                    docker('commit',setup,image,timeout=120)
                finally:
                    self.remove(setup,bot_id)
            self.log(bot,'Installation ready. Applying saved revision '+str(bot['revision'])+'…','deploying')
            env_file = temp/'environment'
            env_file.write_text('\n'.join(k+'='+v for k,v in env.items())+'\n')
            env_file.chmod(0o600)
            self.remove(staged,bot_id)
            try:
                # Prepare ownership in a separate container before dropping runtime privileges.
                prep = 'tw-prep-'+bot_id
                prepared = image+'-ready'
                if docker('image','inspect',prepared,check=False).returncode:
                    self.remove(prep,bot_id)
                    docker('create','--name',prep,'--label','tw.managed='+bot_id,'--network','none',
                           '--memory','128m','--pids-limit','32',image,'/bin/sh','-c','chown -R 1000:1000 /app')
                    try:
                        docker('start','-a',prep,timeout=60)
                        if inspect(prep)['State']['ExitCode']!=0:
                            raise RuntimeError('Could not prepare source permissions')
                        docker('commit',prep,prepared,timeout=120)
                    finally:
                        self.remove(prep,bot_id)
                # No host binds, devices, ports, Docker socket, or privileged mode are exposed.
                docker('create','--name',staged,'--label','tw.managed='+bot_id,'--label','tw.revision='+str(bot['revision']),
                       '--network',NETWORK,'--user','1000:1000','--cap-drop','ALL','--security-opt','no-new-privileges',
                       '--memory','512m','--memory-swap','512m','--cpus','1','--pids-limit','128','--init',
                       '--restart','unless-stopped','--log-opt','max-size=2m','--log-opt','max-file=2',
                       '--env-file',str(env_file),'--workdir','/app',prepared,*args)
                # Tar sets uid/gid 1000 for the private .env file in the stopped container.
                import tarfile
                archive = temp/'env.tar'
                with tarfile.open(archive,'w') as tar:
                    info = tarfile.TarInfo('.env'); payload=env_file.read_bytes()
                    info.size=len(payload); info.uid=info.gid=1000; info.mode=0o600
                    tar.addfile(info,io.BytesIO(payload))
                with archive.open('rb') as stream:
                    result=subprocess.run(['/usr/bin/docker','cp','-a','-',staged+':/app'],stdin=stream,capture_output=True)
                if result.returncode:
                    raise RuntimeError('Could not provision private environment file')
                old = self.owned(name,bot_id)
                backup = 'tw-backup-'+bot_id
                self.remove(backup,bot_id)
                if old:
                    docker('stop','-t','10',name); docker('rename',name,backup)
                try:
                    docker('rename',staged,name); docker('start',name)
                    time.sleep(3)
                    state = inspect(name)['State']
                    if not state['Running'] or state.get('Restarting'):
                        raise RuntimeError('Bot exited during startup. Check its entry file, token, and dependencies.')
                except Exception:
                    failed = docker('logs','--tail','100',name,check=False)
                    self.log(bot,failed.stdout+failed.stderr,'error')
                    self.remove(name,bot_id)
                    if old:
                        docker('rename',backup,name)
                        if old['State']['Running']:
                            docker('start',name)
                    raise
                self.remove(backup,bot_id)
                self.log(bot,'Saved revision deployed successfully.\n','running')
                self.db.execute('UPDATE bots SET published_at=COALESCE(published_at,?) WHERE id=?',(int(time.time()),bot_id))
                self.cleanup_images(bot_id,keep={image,prepared})
            finally:
                self.remove(staged,bot_id)

    def cleanup_images(self, bot_id, keep=()):
        for tag in docker('image','ls','--format','{{.Repository}}:{{.Tag}}').stdout.splitlines():
            if tag.startswith('tidalwave-managed:'+bot_id+'-') and tag not in keep:
                docker('image','rm',tag,check=False)

    def job(self, job):
        bot_id=identifier(job['bot_id'])
        row=self.db.execute('SELECT * FROM bots WHERE id=?',(bot_id,)).fetchone()
        if row is None:
            raise ValueError('Bot no longer exists')
        bot=dict(row); action=job['action']; name='tw-bot-'+bot_id
        if action=='deploy':
            if shutil.disk_usage(PRIVATE).free<4*1024**3:
                raise RuntimeError('Deployment needs at least 4 GB free server disk')
            self.deploy(bot)
        elif action in {'start','stop','restart'}:
            if not self.owned(name,bot_id):
                raise ValueError('Deploy the bot before starting or stopping it')
            docker(action,name)
            self.log(bot,'Runtime action completed: '+action,'stopped' if action=='stop' else 'running')
        elif action=='delete':
            for prefix in ('tw-bot-','tw-setup-','tw-staged-','tw-prep-','tw-backup-'):
                self.remove(prefix+bot_id,bot_id)
            self.cleanup_images(bot_id)
            self.db.execute('DELETE FROM bots WHERE id=?',(bot_id,))
            self.cleanup_uploads()
        else:
            raise ValueError('Unknown job action')

    def cleanup_uploads(self):
        referenced={row[0]+'.zip' for row in self.db.execute('SELECT source FROM bots')}
        for path in (SHARED/'uploads').glob('*.zip'):
            if path.name not in referenced and ID.fullmatch(path.stem) and time.time()-path.stat().st_mtime>3600:
                path.unlink()

    def refresh(self):
        for row in self.db.execute('SELECT * FROM bots').fetchall():
            self.heartbeat()
            bot=dict(row); name='tw-bot-'+identifier(bot['id']); info=self.owned(name,bot['id'])
            if not info:
                continue
            if bot['status']=='error':
                continue  # Keep a failed deployment's diagnostics until an explicit action.
            state=info['State']; status='running' if state['Running'] else 'stopped'
            if state.get('Restarting') or state.get('OOMKilled') or (not state['Running'] and state['ExitCode']!=0):
                status='error'
            # Avoid a rapid automatic crash loop; the administrator can inspect logs and restart.
            if info.get('RestartCount',0)>=5:
                docker('stop',name); status='error'
            usage=docker('container','inspect','--size',name)
            if json.loads(usage.stdout)[0].get('SizeRw',0)>512*1024**2 or shutil.disk_usage(PRIVATE).free<3*1024**3:
                docker('stop',name)
                self.log(bot,'Bot stopped: writable container data exceeded 512 MB or the server disk reserve was reached.','error')
                continue
            logs=docker('logs','--tail','200',name,check=False)
            self.log(bot,'[Deployed revision '+info['Config']['Labels'].get('tw.revision','?')+']\n'+logs.stdout+logs.stderr,status)

    def run(self):
        # Recover only known managed containers after an interrupted job.
        for job in self.db.execute("SELECT * FROM jobs WHERE state='running'").fetchall():
            bot_id=identifier(job['bot_id'])
            for prefix in ('tw-setup-','tw-prep-','tw-staged-'):
                self.remove(prefix+bot_id,bot_id)
            backup='tw-backup-'+bot_id
            if self.owned(backup,bot_id):
                self.remove('tw-bot-'+bot_id,bot_id)
                docker('rename',backup,'tw-bot-'+bot_id)
                docker('start','tw-bot-'+bot_id)
            self.db.execute("UPDATE bots SET status='error',logs=logs || ? WHERE id=?",('\nJob interrupted by runner restart. Review logs, then retry.',bot_id))
        self.db.execute("UPDATE jobs SET state='failed',finished_at=? WHERE state='running'",(int(time.time()),))
        last_refresh=0
        while True:
            self.heartbeat()
            job=self.db.execute("SELECT * FROM jobs WHERE state='queued' ORDER BY id LIMIT 1").fetchone()
            if job:
                self.db.execute("UPDATE jobs SET state='running' WHERE id=?",(job['id'],))
                try:
                    self.job(job)
                    state='done'
                except Exception as error:
                    state='failed'
                    row=self.db.execute('SELECT * FROM bots WHERE id=?',(job['bot_id'],)).fetchone()
                    if row:
                        message=str(error) if isinstance(error,(ValueError,RuntimeError)) else 'Runner operation failed ('+type(error).__name__+').'
                        self.log(dict(row),row['logs']+'\n'+message,'error')
                self.db.execute('UPDATE jobs SET state=?,finished_at=? WHERE id=?',(state,int(time.time()),job['id']))
            elif time.monotonic()-last_refresh>10:
                self.refresh(); self.cleanup_uploads(); last_refresh=time.monotonic()
            time.sleep(2)

if __name__=='__main__':
    Runner().run()
