# Event voucher QR runtime

Pinned PHP dependencies: `composer.lock` (BaconQrCode BSD-2-Clause, DASPRiD Enum BSD-2-Clause). Install with `composer install --no-dev --no-scripts --no-plugins` here. Runtime is private, outside the public webroot; packaged dependencies and their licenses must be included in deployments/installers.

Scanner: `../public/voucher-jsqr-1.4.0.js`, jsQR 1.4.0, Apache-2.0. Upstream https://github.com/cozmo/jsQR, downloaded from the npm registry with package-integrity verification. SHA-256 of the browser file: `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859`. License: `../public/voucher-jsqr-LICENSE.txt`.

QR payload: `KH:1:<32 uppercase hexadecimal event id>:<16 uppercase hexadecimal voucher code>`. Contains no name, email or bank details. All generation/decoding is on our server/device; no external QR service. Camera images stay in the browser. Scan performs only an authenticated lookup; authorized staff must explicitly redeem by CSRF-protected POST.
