# ADR 0013: Review-mode upload requests carry an approval workflow for influencer content

Date: 2026-10-19 · Status: retired 2026-11-17 (the influencer review feature was removed; plain upload requests with per-file Accept and Reject remain) · Amends: the access-lifecycle design (operator decision Q3), for review-mode upload requests only

## Context
The organization works with influencers who post content about it. Staff must approve each piece before it is posted, send
feedback when it needs changes, and see the revision that answers that feedback.

On 2026-10-06 the operator decided (Q3) that approval-required flags and approver workflows are **won't do**. Guest
intake lands files `pending_review`, and a staff member accepts or rejects each file on its own
(`IntakeReview`). That is not an approval: it cannot say "this set of files, this version, is approved", it keeps no
feedback, and a guest cannot revise what they sent.

## Decision
Operator decisions of 2026-10-19:
- **OD1 Guest identity.** One review-mode upload request per influencer (`dam_upload_requests.review_mode`), reached
  by their personal token link plus a 4-digit PIN, which is the link's existing password (hashed, never returned
  after creation). No guest accounts.
- **OD2 Notification.** The influencer's personal link is their status page. After each decision a reviewer copies a
  ready-made message for the influencer. No email for now; a dated TODO marks the SMTP follow-up.
- **OD3 Reviewers.** Anyone holding `dam.upload_requests.review` who passes `IntakeReview::canDecide()` for the
  request (`dam.asset.update` or full admin, plus access to the intake folder). No named reviewers.
- **OD4 Approvals, for review-mode requests only.** This reverses Q3 for those requests. Every other upload request
  keeps per-file Accept and Reject unchanged, and `IntakeReview` stays "not an approval workflow" for them.

How it works:
- **Model.** A *content submission* (`dam_content_submissions`) has *versions*
  (`dam_content_submission_versions`). A version is one immutable set of files sent together
  (`dam_content_submission_files`), each pinned to its asset's current original revision and sha256. Its
  `fingerprint` is the SHA-256 of the sorted `{asset_file_id}:{sha256}` lines. *Feedback*
  (`dam_content_submission_feedback`) is `guest` (shown to the influencer) or `internal` (staff only).
- **One writer.** `SubmissionWorkflow` makes every change. Each step runs in one transaction that holds the
  submission row `FOR UPDATE` and compares the version id with `current_version_id` under that lock.
  - submit: v1, from this visit's finished uploads only. Each upload belongs to at most one version, ever.
  - revise (from any state): the next version. The version it replaces becomes `superseded`, unless it was
    approved, which stays approved as history. The submission returns to `submitted`.
  - startReview: `submitted` to `in_review`.
  - approve (from `submitted` or `in_review`): the reviewer sends the version id and fingerprint they saw. A stale
    version or fingerprint is refused (409 `stale_version`). So is a file whose asset was trashed or whose current
    revision is no longer the pinned one (409 `files_changed`). The version's files go into the library through
    `IntakeReview::accept` in the same transaction. If any file is not accepted, nothing is approved (422
    `files_unavailable`).
  - requestChanges (from `submitted`, `in_review` or `approved`): needs a message for the influencer of at least 10
    characters (422 `message_required`). After an approval the submission is no longer ready to post, but the
    approved version keeps its `approved` state and `approved_version_id` (history).
  - Any other step is refused (409 `invalid_transition`).
- **Lock order.** On approve: the `DAM_ACCESS_MODEL` state row (shared, `AccessModel::sharedly()`, ADR 0010), then
  the submission row, then the files' asset rows. Accepting writes the files' access lists, so it must not cross an
  access-model switch. On submit and revise: the upload request row, then the submission row.
- **What the guest sees.** Their own submissions with state, next step, version history, the names they gave their
  files, and guest feedback. Never internal notes, staff names or emails, ids other than `public_id`, asset ids,
  fingerprints or the folder name (`GuestSubmissionPresenter`).
- **PIN budget.** On top of the per-visitor and per-link minute limits, a review link takes 20 wrong PINs an hour
  from every address together, then answers `locked` until the hour has passed. The PIN is a second factor: an
  attacker needs the 40-character link token first, then on average about 250 hours of guessing per link, and
  every lockout is recorded as a security event.

## Consequences
- An ordinary upload request behaves exactly as before. On a review-mode request the staff page links to the
  submissions queue instead of showing per-file Accept and Reject.
- Files of a superseded or sent-back version stay `pending_review` and private in the intake folder. Approval never
  lets them into the library.
- Closing or expiring a review request also closes the influencer's status page, because every portal route opens
  only while the request accepts files. Review-mode requests default to no expiry.
- An approval refers to one version. A later revision does not change it: the approved version stays visible in the
  history, and only the current state says whether the submission is ready to post.

## 2026-10-20 extension: reusable influencer entry and reviewer assignment

The operator requested a saveable `/influencers` signup/sign-in entry and named reviewer assignment. This extends
the original no-account/no-assignment decisions above. An influencer identity is a normalized handle and display
name attached to one private review request; its PIN remains the existing request password hash. It is not an
admin account or a Library grant. Full administrators explicitly select one review request as the signup template;
new identities copy its folder, limits, instructions and reviewer selection into separate requests. Later template
changes do not silently change existing accounts. No folder is inferred and signup is unavailable until configured.

`reviewer_ids = null` preserves existing eligibility. An explicit list narrows eligibility to its members, with full
administrator override; an empty list permits only full administrators. Assignment never grants folder access or
review permission. Web/API intake reads, submission reads/decisions and notifications honor this restriction.
Request creators retain management access; an unassigned creator's page omits its inaccessible submission panel.

The public handle is discoverable through registration collisions and is not a secret second factor. Login returns
the same failure for unknown, wrong, closed and privately locked accounts, performs a hash operation on each path,
and applies entry-route throttles in addition to the existing per-link PIN budget. Existing personal token links
continue to work unchanged. PIN changes/token reissue invalidate the account session fingerprint and portal visits.

Account login uses a separate budget namespace with the same serialized limits, protecting the secret-token link
from public-handle lockout. Derived requests retain the staff-owner revocation contract. The setup UI states this;
full administrators can explicitly transfer ownership of an open personal request after staff offboarding to
restore access. Transfer neither reopens a closed request nor changes any other account's owner.
