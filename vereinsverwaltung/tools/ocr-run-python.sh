#!/bin/sh
set -eu
runtime=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
python=$(CDPATH= cd -- "$runtime/../python" && pwd)
export PYTHONHOME="$python/usr"
export PYTHONPATH="$runtime/python:$python/usr/lib/python3/dist-packages:$python/opt/pdfdeps"
export PYTHONDONTWRITEBYTECODE=1
exec "$python/lib64/ld-linux-x86-64.so.2" --library-path "$runtime/python-compat:$python/lib/x86_64-linux-gnu:$python/usr/lib/x86_64-linux-gnu" "$python/usr/bin/python3.11" "$@"
