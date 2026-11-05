"""Private launcher for one OCR job in a networkless, read-only Docker container."""
import json
import os
import pathlib
import re
import signal
import subprocess
import sys


def main():
    if len(sys.argv) != 8 or sys.argv[1] != '--config' or sys.argv[3:6] != ['-X', 'utf8', '-s']:
        raise ValueError('Only the fixed invoice recognition command is allowed')
    path = pathlib.Path(sys.argv[2])
    if path.is_symlink() or not path.is_file() or path.stat().st_mode & 0o077:
        raise ValueError('Private launcher configuration required')
    config = json.loads(path.read_text())
    # argv is --config FILE -X utf8 -s SCRIPT JOB: seven arguments after executable.
    script = pathlib.Path(sys.argv[6]).resolve(strict=True)
    job = pathlib.Path(sys.argv[7]).resolve(strict=True)
    if str(script) != config['tools'] + '/recognize-invoice.py' or not re.fullmatch(r'/tmp/kulturhus-ocr-[a-f0-9]{32}', str(job)):
        raise ValueError('Only a registered temporary invoice job is allowed')
    if not re.fullmatch(r'debian@sha256:[a-f0-9]{64}', config['image']):
        raise ValueError('Pinned verified container image required')
    roots = [pathlib.Path(config[name]).resolve(strict=True) for name in ['runtime', 'tools']]
    if any(not root.is_dir() or len(root.parts) < 4 for root in roots) or not job.is_dir():
        raise ValueError('Private runtime paths required')
    docker = '/usr/local/bin/docker' if pathlib.Path('/usr/local/bin/docker').is_file() else '/usr/bin/docker'
    name = 'kh-invoice-ocr-' + job.name.removeprefix('kulturhus-ocr-')
    command = [docker, 'run', '--interactive', '--rm', '--name', name, '--network', 'none', '--read-only',
               '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges=true',
               '--user', str(os.getuid()) + ':' + str(os.getgid()), '--pids-limit', '64',
               '--memory', '512m', '--cpus', '1', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m',
               '--env', 'KH_OCR_CONTAINER_ISOLATION=1', '--env', 'OMP_THREAD_LIMIT=1',
               '--env', 'OMP_NUM_THREADS=1', '--workdir', str(job)]
    for root in roots:
        command += ['--mount', 'type=bind,src=' + str(root) + ',dst=' + str(root) + ',readonly']
    command += ['--mount', 'type=bind,src=' + str(job) + ',dst=' + str(job), config['image'],
                str(roots[0]) + '/ocr-5.5.3/run-python', '-X', 'utf8', '-s', str(script), str(job)]

    def stop(signum, _frame):
        subprocess.run([docker, 'kill', name], stdin=subprocess.DEVNULL,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        raise SystemExit(128 + signum)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    process = subprocess.Popen(command)
    try:
        return process.wait(timeout=90)
    except subprocess.TimeoutExpired:
        stop(signal.SIGTERM, None)


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (ValueError, OSError, KeyError, subprocess.SubprocessError):
        sys.stderr.write('Private isolated invoice runtime could not start\n')
        raise SystemExit(1)
