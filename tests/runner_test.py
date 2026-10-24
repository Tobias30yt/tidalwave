import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
import zipfile

spec=importlib.util.spec_from_file_location('worker',Path(__file__).parents[1]/'runner/worker.py')
worker=importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)

class RunnerTests(unittest.TestCase):
    def archive(self, names):
        data=io.BytesIO()
        with zipfile.ZipFile(data,'w') as z:
            for name in names: z.writestr(name,'print("test")')
        return data.getvalue()
    def test_zip_paths(self):
        for name in ['../outside','/root/file','a/../../b','a\\b','x/.env','x/.git/config','x//y']:
            with self.subTest(name=name),tempfile.TemporaryDirectory() as d:
                with self.assertRaises(ValueError): worker.extract_source(self.archive([name]),Path(d))
    def test_strip_common_root(self):
        with tempfile.TemporaryDirectory() as d:
            worker.extract_source(self.archive(['project/bot.py','project/lib/helper.py']),Path(d))
            self.assertTrue((Path(d)/'bot.py').is_file())
            self.assertTrue((Path(d)/'lib/helper.py').is_file())
    def test_symlink(self):
        data=io.BytesIO()
        with zipfile.ZipFile(data,'w') as z:
            info=zipfile.ZipInfo('evil');info.create_system=3;info.external_attr=0o120777<<16
            z.writestr(info,'/etc/passwd')
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(ValueError): worker.extract_source(data.getvalue(),Path(d))
    def test_command_is_argv(self):
        self.assertEqual(worker.start_args('python','python "my bot.py"'),['/opt/venv/bin/python','my bot.py'])
        for cmd in ['bash run.sh','node bot.js && curl evil','node bot.js\nrm -rf /']:
            with self.assertRaises(ValueError): worker.start_args('node',cmd)
    def test_environment(self):
        self.assertEqual(worker.environment('TOKEN=a=b\nX=hello world'),{'TOKEN':'a=b','X':'hello world'})
        for value in ['LD_PRELOAD=x','PATH=x','X=a\nX=b','bad key=x','X=bad\x00']:
            with self.assertRaises(ValueError): worker.environment(value)
    def test_identifiers(self):
        for value in ['../evil','x; reboot','a'*31,'a'*33]:
            with self.assertRaises(ValueError): worker.identifier(value)

if __name__=='__main__': unittest.main()
