# SEPA schema

`pain.008.001.08.xsd` is the unmodified ISO 20022 default-namespace schema, retrieved on 2026-09-08 from the maintained hbci4j/hbci4java source distribution:

https://raw.githubusercontent.com/hbci4j/hbci4java/master/src/main/resources/pain.008.001.08.xsd

SHA-256: `7edf4e4ce34c47a5567af6a327e22af4ed4007f715822af9f353c94ecc10f5ba`

The official ISO download endpoint https://www.iso20022.org/message/14481/download was also checked, but its direct download reset the local connection. We therefore record the actual mirror used rather than claim an original-server download.

The EPC 2025 SDD CORE customer-to-PSP guidelines require this message version and state that production instances use the default ISO namespace, not the EPC technical validation subset namespace:

https://www.europeanpaymentscouncil.eu/document-library/implementation-guidelines/sepa-direct-debit-core-customer-psp-implementation-1

Schema validation verifies the XML structure; it does not attest bank acceptance, mandate validity or all business rules. The application separately enforces its narrowly supported German domestic EUR CORE contribution workflow, current mandate, verified treasurer approval, explicit notice date and minimum 14-day advance notice. No bank submission is made by this generator. Direct debit bank-contract testing remains required with the registered application and actual bank access.
