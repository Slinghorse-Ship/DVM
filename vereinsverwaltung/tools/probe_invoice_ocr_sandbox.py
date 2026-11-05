"""Harmless confinement probe. Only opens forbidden files; never reads their bytes."""
import errno,json,os,pathlib,socket,sys
from invoice_ocr_sandbox import activate
request=json.load(sys.stdin);job=pathlib.Path(sys.argv[1]).resolve();forbidden=request['forbidden_existing_file'];outside=request['outside_new_file']
if not os.path.isfile(forbidden) or os.path.exists(outside):raise RuntimeError('Probe requires existing read target and absent outside write target.')
result=activate(job,request['runtime'])
def denied(call):
    try:
        value=call()
        if isinstance(value,int):os.close(value)
        elif hasattr(value,'close'):value.close()
        return False
    except OSError as e:return e.errno in (errno.EACCES,errno.EPERM)
result['outside_read_denied']=denied(lambda:os.open(forbidden,os.O_RDONLY))
result['outside_write_denied']=denied(lambda:os.open(outside,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600))
result['socket_denied']=denied(lambda:socket.socket(socket.AF_INET,socket.SOCK_STREAM))
file=job/'sandbox-probe.txt';file.write_text('synthetic sandbox probe',encoding='utf-8');result['job_write_allowed']=file.read_text()=='synthetic sandbox probe'
result['chmod_denied']=denied(lambda:os.chmod(file,0o600))
file.unlink()
print(json.dumps(result));sys.exit(0 if all(result.get(k) for k in ['enabled','outside_read_denied','outside_write_denied','socket_denied','job_write_allowed','chmod_denied']) else 1)
