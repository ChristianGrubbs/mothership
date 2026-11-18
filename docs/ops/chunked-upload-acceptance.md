# Chunked upload live acceptance

This record covers the original upload failure through the public Mothership route.

## Source fixture

- Local source: a 2 GB `FULL_WIP_2G.mp4` on the operator's Mac
- Byte size: `67,281,279`
- SHA-256: `f4411ea916982caa2009a9bef675e32d009ad7f7d03a4f407cd5359e5a203d03`
- Public application: the deployed runtime at the time

## Release evidence

Verified on 2026-09-08 through the public application, using the operator's original file.

- Deployed application commit: `33b0cb231953537475ad10e3c044f6e09e3b3193` (PR65).
  The deployment finished with all application services healthy and its application probe passing.
- Upload UUID: `bbb71917-6655-4e7e-a092-57b712a26c74`.
- Asset ID: `7662`; stored path:
  `assets/Root/<folder>/FULL_WIP_2G.mp4`.
- Stored size: `67,281,279` bytes. A server-side stream hash equals the source SHA-256 above.
- Browser: operator initiated the upload; panel reached `1 of 1 uploaded`. The asset inspector
  loaded a 45-second video (`readyState=4`), and playback advanced from 0 to 19.132 seconds without
  a media error. File type is `video`.
- Network: initialization returned 201, all three chunk PUTs returned 200, completion returned
  202, and polling reached `completed`. No HTTP 413 occurred in the observed upload requests.
  The transfer occurred during the final deployment stages; nginx was subsequently recreated,
  so its earlier container access log was unavailable for an independent historical audit.
- Manifest is `completed`; asset ID is populated; remaining part rows: `0`; staging directory:
  absent.
- Scheduler: `dam:uploads:expire` appears hourly in the live scheduler's `schedule:list`.
  Both chunk-upload migrations are applied, and the live queue worker can load `FinalizeChunkUpload`.
- JSONL lifecycle: initialized at `15:51:03.380438Z`, queued at `15:51:07.285965Z`, assembling
  at `15:51:09.638775Z`, completed at `15:51:13.985808Z`. The four records contain IDs, byte/chunk
  counts and state; no filename, path, credentials or request headers.

## Review and validation

PR64 isolated every verifier PHP invocation (the verifier of the runtime at the time) from the deployed bootstrap cache with an
empty writable tmpfs. The pre-handoff verifier had incorrectly seeded production through its cached
configuration and replaced the operator's admin account. The account was restored from existing
Keychain credentials; public login and a live password-hash comparison pass. This does not certify
that unrelated historical account-owned metadata survived that earlier seeder run.

PR65 corrects folder destination handling, expiry enforcement before completion queueing, filename
validation, and generic-MIME audio/video classification. Local validation passed 60 focused PHP
tests (334 assertions), 15 browser tests, and Pint on all four changed PHP files. Runtime tooling
validation ran 63 unit tests successfully, including three platform skips. Canonical
verification on the deployed commit passed all 60 focused tests (334 assertions) in 90.88 seconds;
run `20c99d9a-a016-4b15-9569-c7431d4b236e` exited 0 and dropped disposable database
`mothership_test_5aebf24d05d74bf9`. The production admin still exists and its credential comparison
passes after that run. GitHub Actions could not start because
the account's billing/spending check rejected the jobs; no CI pass is claimed.

The operator explicitly deferred StylePress debugging to the next task unless it breaks Mothership
as a whole. Home/StylePress failures in the broader suite are recorded separately from this upload
acceptance; the working public login, upload and video playback do not depend on resolving them.

The canonical broader DAM run finished with **1,162 passed, 10 failed, 3 skipped (3,907 assertions)**
in 1,358.89 seconds. Run `09bd7335-ccc9-488f-ba3d-6f1304ae8199` exited 2, dropped disposable database
`mothership_test_30a825d5b1c946e4`, and released its checkout lock. The run log and exit record were kept on the runtime host of the time, outside this repository.

- Eight deferred StylePress failures: HomeTest (one: catalog count 254 versus 148),
  ImageEditorDraftTest (three StylePress requests: 422 versus 202), StylePressCatalogTest (two),
  and StylePressWidgetTest (two). No StylePress repairs were attempted after the operator's deferral.
- Two pre-existing fillable-array expectations: AssetModelTest line 27 and DirectoryModelTest
  line 22. Both models and both tests are unchanged from the approved pre-chunk base
  `66469d3d7365f529bd123a1dc3ac139f37d77b60`; the earlier handoff already recorded these failures.

The broader suite is therefore **not green**; this record certifies the focused upload release and
the original-file public acceptance, with those separate failures explicitly retained.

## Acceptance procedure

1. Deploy the merged commit ([runtime guide](studio-runtime.md)) and confirm migrations, caches,
   nginx, FPM, queue workers, scheduler, and health probes.
2. Sign in to the public application, upload the source fixture through the DAM picker,
   and confirm more than one chunk request before the panel reaches 100%.
3. Record the upload UUID and asset ID, then compare the live stored object byte size and SHA-256 with
   the source values above and open video playback.
4. Confirm the manifest is `completed`, its part rows are gone, its `.uploads/<uuid>` staging directory
   is absent, nginx recorded no HTTP 413 for the transfer, and the JSONL transitions contain no filename
   or path fields.

## Rollback

The pre-application-deploy commit was `737524ccdeea104dd51abb261b76badebe0da18f`
(PR64, verifier isolation). If acceptance fails, return production to that commit
through the normal branch/PR workflow, then redeploy ([runtime guide](studio-runtime.md)).

Do not reverse the chunk-upload migrations or delete staged data manually; the legacy multipart routes
remain available and `dam:uploads:expire` removes abandoned manifests safely.
