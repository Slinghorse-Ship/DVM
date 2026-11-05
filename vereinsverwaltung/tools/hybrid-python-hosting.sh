#!/bin/sh
# Install as python-private inside /tmp/kulturhus-hybrid-einvoice/<version>/.
# Preserve PYTHONPATH supplied by HybridEInvoice; never use the PDF wrapper
# that overwrites it with its own ReportLab dependencies.
umask 077
runtime=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1
hosting=${runtime%/tmp/kulturhus-hybrid-einvoice/*}
export LD_LIBRARY_PATH="$hosting/tmp/kulturhus-verein/kosit-1.6.3-xrechnung-20260831/glibc-compat-2.41"
export PYTHONHOME="$hosting/tmp/kulturhus-verein/python/usr"
exec "$hosting/tmp/kulturhus-verein/python/usr/bin/python3.11" "$@"
