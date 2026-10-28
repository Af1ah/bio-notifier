# P1 implementation and deployment notes

Date: 19 October 2026. Audience: application maintainers. Scope: local Laravel application and Go gateway. This records software corrections. The full batch was subsequently deployed on 20 October 2026; see docs/developer/production-rollout-2026-10-20.md. Deployment health checks do not establish new physical-device compatibility.

## Implemented boundaries

| Area | Implemented behavior | Limit |
|---|---|---|
| Device profiles | Runtime family policy gates commands at submission and dispatch; unknown families allow discovery reads; INFO discovery is automatic and bounded | Built-in families are legacy fingerprint, conventional face and the observed visible-light face family. The editable registry is not a universal firmware adapter |
| Face representations | Conventional FACE, visible-light type-9 BIODATA and enrollment JPEG remain separate; compatible AI templates can be pushed independently of JPEGs | A recognition template cannot recreate its photograph; current MAGNUM photo export is not newly established |
| Credential identity | Source serial, raw type, slot/index, version/format, validity/duress, content hash, byte length and observation time are retained | Ordinary pushes reject invalid/duress credentials. Cross-device unknown or mismatched algorithms are blocked. Missing records are not deleted after partial reads |
| Read/application results | Multipart queries require every numbered packet, reject conflicting packet evidence and correlate data receipts with commands; repeated reads have independent observations | Legacy responses without authoritative inventory counts show “Data saved; inventory completeness unconfirmed”. No-data responses do not erase saved credentials |
| Metadata contract | Both services accept the bounded metadata schema; observed serial cannot replace registered identity; platform and model remain separate | Hardware counters and family identification do not prove employee enrollment |
| Timezone | Tenant timezone governs gateway punch projection and attendance wall-time interpretation; original/UTC evidence is retained | Existing history is not shifted. Once attendance exists, timezone changes require a reviewed migration. Ambiguous DST hours still need a future UTC-storage migration |
| Storage protection | Credential arrays, Laravel submission/outbox parameters, private JPEGs and Go command payload/wire bytes are encrypted; old data is backfilled with bounded, resumable work | Historical backups, host disk encryption, retention and key custody remain operational responsibilities |
| Push dependencies | Credential writes depend on the same device/version's confirmed user update; rejected/cancelled/expired parents block dependent dispatch | There is no automatic destructive rollback or blind replay of successful device mutations |

The eBio authenticated photo refresh, JPEG validation and profile display code remain in place. Resolved source findings were removed from the SDK comparison document; implementation code was not removed.

## Verification

Local automated tests cover richer metadata and immutable registered identity, duplicate/repeated data reads, distinct receipt application, credential identity and flags, encrypted rows/photos, repeatable legacy backfill, dependency outcomes, profile/version rejection and non-IST overnight attribution. The Go integration suite uses a dedicated test database with disposable schemas and the race detector. Laravel tests use isolated fixtures.

Verification results: the complete Laravel suite passed 257 tests with 1,498 assertions. After the final credential-selection and receipt-recovery adjustments, the affected gateway suite passed 31 tests with 257 assertions. The Go race/PostgreSQL suite passed 43 tests; vet and the gateway build also passed (VCS stamping disabled because this gateway checkout has no usable Git metadata).

These tests do not prove physical recognition, every firmware's query dialect, peak capacity, DST migration or production behavior. The remaining SDK report retains those qualification and extension requirements.

## Deployment sequence

1. Record the current application revision and gateway binary/image. Back up the central database, every affected tenant database, the gateway database and private biometric-photo files. Preserve Laravel APP_KEY and GATEWAY_ENCRYPTION_KEY separately from database backups. Verify recovery access before migration.
2. Pause Laravel queue workers and the scheduler, and put the web application into maintenance mode. Let the existing gateway retain accepted device events durably while downstream application is paused; monitor its configured intake budget.
3. Install the matching Laravel and Go changes. Run the central migrations and then tenant migrations with tenancy initialized for each company. The migrations encrypt existing parameters, credential arrays and biometric-photo files and add receipt-application fields. They do not rewrite attendance or processed payslips.
4. Restart the gateway with its existing encryption key. Its startup migration adds dependency/packet/profile fields and encrypts legacy command parameters/wire values before listeners start. Confirm startup completes successfully before enabling management traffic.
5. Start the supervised Laravel workers and scheduler, then restore web access. Bounded recovery queues INFO for devices missing the new profile observation. Unknown families keep mutations blocked and remain available for manual details refresh.
6. Check one INFO result, a correlated user/template read through tenant application, a rejected-prerequisite push and an ordinary attendance receipt. Compare stored credential/photo hashes and attendance dates without logging raw biometrics. Observe unapplied receipts and failed/parked submissions before broad use.

## Recovery constraints

Encryption backfills are resumable and skip protected values. Their down methods intentionally do not decrypt credentials. Do not roll back to older binaries/casts that expect plaintext after migration; retain code capable of reading the encrypted format. A restore must use matching database/files and keys and account for events accepted since the backup. Do not delete accepted events or resend ambiguous device mutations as a shortcut.

A timezone change after attendance exists is a separate migration task. Do not bypass the model guard or change the Linux clock to reinterpret historical payroll.
