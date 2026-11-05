#!/bin/sh
# Only a verified, credential-free WebBundle enters the Docker build context.
set -eu
task_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
task_repo=$(CDPATH= cd -- "$task_dir/../.." && pwd)
task_image=${1:-dvm:local}
if [ "$#" -gt 0 ]; then shift; fi
task_build=$(mktemp -d /tmp/dvm-docker-build.XXXXXXXX)
chmod 0700 "$task_build"
trap 'rm -rf -- "$task_build"' EXIT HUP INT TERM
task_php=${DVM_PHP:-php}
if [ "$#" -eq 2 ] && [ "$1" = --bundle ]; then
    task_bundle=$2
elif [ "$#" -eq 0 ]; then
    "$task_php" "$task_repo/distribution/build-webspace.php" --target="$task_build/package" >/dev/null
    task_bundle=$task_build/package
else
    echo 'Usage: build.sh [IMAGE] [--bundle /absolute/verified-webspace-package]' >&2
    exit 2
fi
mkdir "$task_build/context"
"$task_php" "$task_dir/prepare-context.php" "$task_bundle" "$task_build/context/seed"
for task_file in Dockerfile initialize.php apache-config.php entrypoint.sh dvm php.ini; do
    cp "$task_dir/$task_file" "$task_build/context/$task_file"
done
cp "$task_repo/distribution/packages/linux-runtime/blobs/pypdf-6.18.0-py3-none-any.whl" "$task_build/context/"
cp "$task_repo/distribution/packages/linux-runtime/sources/reportlab-4.4.9.tar.gz" "$task_build/context/"
# Keep the managed Docker config/proxy and registry credentials intact.
task_source=$("$task_php" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $p["source"];' "$task_build/context/seed/app-files.json")
set -- --build-arg "PHP_IMAGE=${DVM_PHP_IMAGE:-php:8.4-apache-bookworm}" --build-arg "NODE_IMAGE=${DVM_NODE_IMAGE:-node:22-bookworm-slim}" --build-arg "DVM_SOURCE=$task_source" --build-arg "DVM_VERSION=${DVM_IMAGE_VERSION:-2026.11.05-1862}"
if [ -n "${CODEX_PROXY_CERT:-}" ]; then
    docker build "$@" --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt -t "$task_image" "$task_build/context"
else
    docker build "$@" -t "$task_image" "$task_build/context"
fi
