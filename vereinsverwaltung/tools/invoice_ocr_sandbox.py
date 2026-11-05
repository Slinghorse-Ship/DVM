"""Fail-closed Linux x86_64 OCR confinement; call before native document parsing."""
import ctypes, errno, os, pathlib, platform, stat, sys

class SandboxError(RuntimeError): pass
class Ruleset(ctypes.Structure):
    _fields_=[('fs',ctypes.c_uint64),('net',ctypes.c_uint64),('scoped',ctypes.c_uint64)]
class PathRule(ctypes.Structure):
    _pack_=1
    _fields_=[('allowed',ctypes.c_uint64),('parent',ctypes.c_int32)]
class Filter(ctypes.Structure):
    _fields_=[('code',ctypes.c_ushort),('jt',ctypes.c_ubyte),('jf',ctypes.c_ubyte),('k',ctypes.c_uint32)]
class Program(ctypes.Structure):
    _fields_=[('length',ctypes.c_ushort),('filter',ctypes.POINTER(Filter))]

def activate(job, config):
    required=config.get('require_sandbox',True)
    if config.get('sandbox_backend')=='docker':
        # The trusted launcher starts a distinct PID/network/mount namespace.
        # Verify its effective kernel policy before loading any document parser.
        if required is not True or sys.platform!='linux' or os.getenv('KH_OCR_CONTAINER_ISOLATION')!='1' or os.getuid()==0:
            raise SandboxError('Isolierter OCR-Container fehlt.')
        status=dict(line.split(':',1) for line in pathlib.Path('/proc/self/status').read_text().splitlines() if ':' in line)
        if int(status.get('CapEff','1').strip(),16)!=0 or status.get('NoNewPrivs','').strip()!='1' or status.get('Seccomp','').strip()!='2':
            raise SandboxError('OCR-Container benötigt entfallene Capabilities, no_new_privs und seccomp.')
        if set(os.listdir('/sys/class/net'))!={'lo'}:
            raise SandboxError('OCR-Container darf keinen Netzwerkzugang besitzen.')
        mounts=[line.split() for line in pathlib.Path('/proc/self/mountinfo').read_text().splitlines()]
        if not any(row[4]=='/' and 'ro' in row[5].split(',') for row in mounts):
            raise SandboxError('OCR-Container benötigt ein schreibgeschütztes Root-Dateisystem.')
        job=pathlib.Path(job).resolve(strict=True)
        if not job.is_dir() or stat.S_IMODE(job.stat().st_mode)&0o077:
            raise SandboxError('OCR-Arbeitsverzeichnis muss privat sein.')
        return {'enabled':True,'backend':'docker','seccomp':True,'network':False,'read_only_root':True}
    if required is not True:
        # Docker Desktop's WSL2 9P bind mount cannot be covered by Landlock.
        # The local demo is already process- and network-isolated by Docker;
        # production/Linux installations must still use Landlock below.
        if required is False and (sys.platform=='win32' or os.getenv('KULTURHUS_LOCAL_DEMO')=='1'):
            return {'enabled':False,'reason':'explicit_local_demo_container'}
        raise SandboxError('Sandbox darf nur für lokale Windows-Tests ausgeschaltet werden.')
    if sys.platform!='linux' or platform.machine()!='x86_64':raise SandboxError('OCR-Sandbox benötigt Linux x86_64.')
    job=pathlib.Path(job).resolve(strict=True)
    if not job.is_dir() or stat.S_IMODE(job.stat().st_mode)&0o077:raise SandboxError('OCR-Arbeitsverzeichnis muss privat sein.')
    libc=ctypes.CDLL(None,use_errno=True);libc.syscall.restype=ctypes.c_long
    def syscall(n,*args):
        result=libc.syscall(ctypes.c_long(n),*args)
        if result<0:raise SandboxError('Sandbox-Systemaufruf fehlgeschlagen: '+str(ctypes.get_errno()))
        return result
    abi=syscall(444,ctypes.c_void_p(),ctypes.c_size_t(0),ctypes.c_uint(1))
    if abi<6:raise SandboxError('Landlock ABI 6 mit Signal-Isolation für sichere OCR-Timeouts erforderlich.')
    # This worker is single-threaded here. All later native threads/children inherit.
    taskdir=pathlib.Path('/proc/self/task')
    if taskdir.exists() and len(list(taskdir.iterdir()))!=1:raise SandboxError('Sandbox muss vor Threads aktiviert werden.')
    fs=(1<<15)-1
    if abi>=5:fs|=1<<15  # IOCTL_DEV, denied except explicit /dev/null access
    rules=Ruleset(fs,3 if abi>=4 else 0,3 if abi>=6 else 0)
    size=24 if abi>=6 else 16 if abi>=4 else 8
    fd=syscall(444,ctypes.byref(rules),ctypes.c_size_t(size),ctypes.c_uint(0))
    try:
        roots=config.get('sandbox_read_roots',['/tmp/kulturhus-verein/python','/tmp/kulturhus-verein/ocr-5.5.3'])
        if not isinstance(roots,list) or len(roots)>4:raise SandboxError('Sandbox-Lesepfade prüfen.')
        roots=[pathlib.Path(p).resolve(strict=True) for p in roots]+[pathlib.Path(__file__).resolve().parent]
        for root in roots:
            if not root.is_dir() or len(root.parts)<4 or root==job or root in job.parents:raise SandboxError('Zu weiter Sandbox-Lesepfad.')
        def allow(path,rights):
            handle=os.open(str(path),os.O_PATH|os.O_CLOEXEC)
            try:syscall(445,ctypes.c_int(fd),ctypes.c_int(1),ctypes.byref(PathRule(rights,handle)),ctypes.c_uint(0))
            finally:os.close(handle)
        for root in roots:allow(root,(1<<0)|(1<<2)|(1<<3))
        # Regular files and directories only; no devices, sockets, executable outputs.
        allow(job,(1<<1)|(1<<2)|(1<<3)|(1<<4)|(1<<5)|(1<<7)|(1<<8)|(1<<13)|(1<<14))
        allow('/dev/null',(1<<1)|(1<<2))
        if libc.prctl(38,1,0,0,0)!=0:raise SandboxError('no_new_privs fehlgeschlagen.')
        syscall(446,ctypes.c_int(fd),ctypes.c_uint(0))
    finally:os.close(fd)
    # Architecture guard also rejects x32 before dispatching x86_64 numbers.
    instructions=[Filter(0x20,0,0,4),Filter(0x15,1,0,0xc000003e),Filter(0x06,0,0,0x80000000),Filter(0x20,0,0,0),Filter(0x35,0,1,0x40000000),Filter(0x06,0,0,0x80000000)]
    # kill(2) must remain available to reap timed-out children. Landlock SIGNAL
    # scope (mandatory ABI >= 6 above) denies signals outside this domain.
    denied=[*range(41,56),90,91,92,93,94,101,129,132,155,165,166,188,189,190,197,198,199,200,234,235,260,261,268,280,248,249,250,272,288,297,298,299,307,308,310,311,321,425,426,427,428,429,430,431,432,438,442,452]
    for number in denied:instructions.extend([Filter(0x15,0,1,number),Filter(0x06,0,0,0x00050000|errno.EPERM)])
    # clone3 is opaque: ENOSYS allows libc to use ordinary clone for subprocesses.
    instructions.extend([Filter(0x15,0,1,435),Filter(0x06,0,0,0x00050000|errno.ENOSYS),Filter(0x06,0,0,0x7fff0000)])
    array=(Filter*len(instructions))(*instructions);program=Program(len(instructions),array)
    if libc.prctl(22,2,ctypes.byref(program),0,0)!=0:raise SandboxError('seccomp fehlgeschlagen.')
    return {'enabled':True,'landlock_abi':abi,'seccomp':True}
