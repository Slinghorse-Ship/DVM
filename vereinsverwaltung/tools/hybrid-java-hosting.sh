#!/bin/sh
# Install as java-private inside /tmp/kulturhus-hybrid-einvoice/<version>/.
umask 077
runtime=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1
hosting=${runtime%/tmp/kulturhus-hybrid-einvoice/*}
exec "$hosting/tmp/kulturhus-verein/kosit-1.6.3-xrechnung-20260831/java-private" "$@"
