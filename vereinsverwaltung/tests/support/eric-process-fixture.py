"""Synthetic process fixture; no SDK, database, certificate or network."""
import ctypes,json,os,subprocess,sys,time
from pathlib import Path

mode=sys.argv[1]
if mode=='alive':
    pid=int(sys.argv[2])
    if os.name=='nt':
        kernel=ctypes.WinDLL('kernel32');kernel.OpenProcess.restype=ctypes.c_void_p
        handle=kernel.OpenProcess(0x100000,False,pid)
        if handle:
            alive=kernel.WaitForSingleObject(ctypes.c_void_p(handle),0)==258
            kernel.CloseHandle(ctypes.c_void_p(handle))
        else:alive=False
    else:
        try:
            os.kill(pid,0);alive=True
            # A terminated orphan may await PID 1 reaping in a container.
            # kill(pid,0) alone also succeeds for this non-running zombie.
            state=Path('/proc')/str(pid)/'stat'
            if state.is_file():alive=state.read_text().rsplit(')',1)[1].strip().split()[0]!='Z'
        except ProcessLookupError:alive=False
    print('alive' if alive else 'stopped');raise SystemExit(0)
data=sys.stdin.buffer.read()
if mode=='echo':
    sys.stdout.buffer.write(data);sys.stderr.buffer.write('synthetisch äöü'.encode('utf8'));raise SystemExit(7)
if mode=='environment':
    print(json.dumps({'private_token': 'VEREIN_ERIC_PROCESS_TOKEN' in os.environ,'private_command': 'VEREIN_ERIC_PROCESS_COMMAND' in os.environ}));raise SystemExit(0)
if mode=='limit':
    sys.stderr.buffer.write(b'x'*1000001);raise SystemExit(0)
if mode=='hang':
    child=subprocess.Popen([sys.executable,'-c','import time;time.sleep(60)'],creationflags=0x08000000 if os.name=='nt' else 0)
    with open(sys.argv[2],'w',encoding='ascii') as output:json.dump([os.getpid(),child.pid],output)
    time.sleep(60)
raise RuntimeError('Unknown synthetic fixture mode')
