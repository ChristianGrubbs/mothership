# Role editor: always-on capabilities

Every signed-in person (never an API integration) holds `dam.hub`, `dam.hub.resources`, `dam.hub.resources.share`, `dam.transfers` and `dam.feedback` without a role grant. `Admin::HUMAN_ALWAYS_ON_PERMISSIONS` is the single list: `Admin::hasPermission()` enforces it and the role create/edit pages read it to render those rows.

- **Display.** The rows show ticked and disabled with an "Always on" pill, plus one sentence above the tree saying so (and that API integrations still need them granted). A parent or child click cannot change them (`x-admin::tree.view` `locked-values`, opt-in; the API-key screens do not pass it).
- **Save.** A locked row is never injected: a role that does not store a key does not gain it, and a role that already stores one keeps it (it is submitted as a hidden value), so a role shared with an API user keeps the grants that user relies on. Locked keys do not make a parent look chosen, so ticking a parent still selects only the real children.
- **Not locked.** `dam.feedback.manage` (triage) stays an ordinary grant.
- **Rollback.** Revert the commit; no data or permission semantics change.
