#!/usr/bin/env sh
# Developer convenience alias; customer installations use the native webspace installer.
set -eu
dvm_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
if [ ! -f "$dvm_root/tools/cloud/bootstrap.sh" ]; then
  printf '%s\n' 'Native Einrichtung: docs/WEBSPACE-INSTALLATION.md. Entwicklung aus Git: bash tools/cloud/bootstrap.sh --setup.' >&2
  exit 2
fi
if [ "$#" -eq 0 ]; then set -- --start; fi
exec bash "$dvm_root/tools/cloud/bootstrap.sh" "$@"
