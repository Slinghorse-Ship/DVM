# Public Apple certificate chain for membership passes

Public certification-authority certificates, downloaded from Apple on 2026-10-31.
These contain public keys only; they are not pass-signing credentials.

- https://www.apple.com/certificateauthority/AppleWWDRCAG4.cer
  SHA-256 `ea4757885538dd8cb59ff4556f676087d83c85e70902c122e42c0808b5bce14c`
- https://www.apple.com/appleca/AppleIncRootCertificate.cer
  SHA-256 `b0b1730ecbc7ff4505142c49f1295e6eda6bcaed7e2c68c5be91b5a11001f024`

The application checks the issuer signature, trust anchor, validity periods,
pass identifier, team identifier and corresponding private key before signing.
A synthetic self-signed certificate used by a format test is not Apple trust.

## Google Wallet API contract

Official API discovery checked on 2026-10-31:
https://walletobjects.googleapis.com/$discovery/rest?version=v1

Neither GenericClass nor Issuer exposes a publication-approval status field.
Reading the selected existing GenericClass verifies access only. The administrator
separately confirms issuer publication approval. The manager creates no classes
and does not claim approval from a successful read. Service-account OAuth uses
only the fixed Google token endpoint and the Wallet issuer scope.
