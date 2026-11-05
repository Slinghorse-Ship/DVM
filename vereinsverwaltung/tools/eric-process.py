"""Bounded memory-only subprocess IO for PHP's Windows runtime."""
import base64,json,os,select,signal,socket,struct,subprocess,sys,threading

def private_job():
    """Windows closes this non-inherited handle on exit, killing descendants."""
    if os.name!='nt':return None
    import ctypes
    from ctypes import wintypes as w
    class Basic(ctypes.Structure):
        _fields_=[('process_time',ctypes.c_int64),('job_time',ctypes.c_int64),('flags',w.DWORD),('minimum',ctypes.c_size_t),('maximum',ctypes.c_size_t),('active',w.DWORD),('affinity',ctypes.c_size_t),('priority',w.DWORD),('scheduling',w.DWORD)]
    class IO(ctypes.Structure):
        _fields_=[(name,ctypes.c_uint64) for name in ('read_ops','write_ops','other_ops','read_bytes','write_bytes','other_bytes')]
    class Extended(ctypes.Structure):
        _fields_=[('basic',Basic),('io',IO),('process_memory',ctypes.c_size_t),('job_memory',ctypes.c_size_t),('peak_process',ctypes.c_size_t),('peak_job',ctypes.c_size_t)]
    kernel=ctypes.WinDLL('kernel32',use_last_error=True)
    kernel.CreateJobObjectW.argtypes=[ctypes.c_void_p,w.LPCWSTR];kernel.CreateJobObjectW.restype=w.HANDLE
    kernel.SetInformationJobObject.argtypes=[w.HANDLE,ctypes.c_int,ctypes.c_void_p,w.DWORD];kernel.SetInformationJobObject.restype=w.BOOL
    kernel.AssignProcessToJobObject.argtypes=[w.HANDLE,w.HANDLE];kernel.AssignProcessToJobObject.restype=w.BOOL
    kernel.GetCurrentProcess.restype=w.HANDLE
    handle=kernel.CreateJobObjectW(None,None);limits=Extended();limits.basic.flags=0x2000
    if not handle or not kernel.SetInformationJobObject(handle,9,ctypes.byref(limits),ctypes.sizeof(limits)) or not kernel.AssignProcessToJobObject(handle,kernel.GetCurrentProcess()):raise RuntimeError('Private process job unavailable')
    return handle

def stop(process):
    if os.name=='nt':
        if process.poll() is None:process.kill()
    else:
        try:os.killpg(process.pid,signal.SIGKILL)
        except ProcessLookupError:pass

def exact(stream,count):
    result=bytearray()
    while len(result)<count:
        part=stream.recv(min(65536,count-len(result)))
        if not part:raise RuntimeError('Parent connection closed')
        result.extend(part)
    return result

def main():
    job=private_job()  # Keep alive until process exit; never expose or inherit it.
    token=os.environ.pop('VEREIN_ERIC_PROCESS_TOKEN');command=json.loads(os.environ.pop('VEREIN_ERIC_PROCESS_COMMAND'))
    if len(token)!=64 or not isinstance(command,list) or not command or any(not isinstance(v,str) or '\0' in v for v in command):raise RuntimeError('Invalid private process configuration')
    port=int(sys.argv[1]);assert len(sys.argv)==2 and 0<port<65536
    with socket.create_connection(('127.0.0.1',port),timeout=5) as connection:
        connection.settimeout(95);connection.sendall(token.encode('ascii'));token=None
        length=struct.unpack('!I',exact(connection,4))[0]
        if length>8388608:raise RuntimeError('Input limit')
        request=exact(connection,length);result={};process=None
        try:
            process=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=os.environ,creationflags=0x08000000 if os.name=='nt' else 0,start_new_session=os.name!='nt')
            outputs=[bytearray(),bytearray()];overflow=threading.Event();finished=threading.Event()
            def collect(index,pipe,limit):
                while True:
                    part=pipe.read(65536)
                    if not part:return
                    if len(outputs[index])+len(part)>limit:overflow.set();stop(process);return
                    outputs[index].extend(part)
            def supply():
                try:process.stdin.write(request);process.stdin.close()
                except (BrokenPipeError,OSError):pass
            def watch_parent():
                while not finished.wait(.1):
                    try:
                        ready,_,_=select.select([connection],[],[],0)
                        if ready and not connection.recv(1,socket.MSG_PEEK):stop(process);return
                    except OSError:stop(process);return
            readers=[threading.Thread(target=collect,args=(0,process.stdout,16000000)),threading.Thread(target=collect,args=(1,process.stderr,1000000))]
            writer=threading.Thread(target=supply);watcher=threading.Thread(target=watch_parent)
            for thread in [*readers,writer,watcher]:thread.start()
            try:exit_code=process.wait(timeout=90)
            except subprocess.TimeoutExpired:stop(process);process.wait();result={'failure':'deadline'}
            finished.set();watcher.join(timeout=1)
            for thread in [*readers,writer]:thread.join(timeout=3)
            request[:]=b'\0'*len(request);request.clear()
            if not result:
                result={'failure':'response-limit'} if overflow.is_set() else {'exit':exit_code,'stdout':base64.b64encode(outputs[0]).decode('ascii'),'stderr':base64.b64encode(outputs[1]).decode('ascii')}
        finally:
            if process:stop(process);process.wait()
            request[:]=b'\0'*len(request);request.clear()
        raw=json.dumps(result).encode('ascii');connection.sendall(struct.pack('!I',len(raw))+raw)

if __name__=='__main__':
    try:main()
    except Exception:raise SystemExit(2)
