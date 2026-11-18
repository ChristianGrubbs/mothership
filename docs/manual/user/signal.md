# Read Signal news

## Before you begin

Signal appears on Home for administrators, under **Studio admin**, as **Signal news**: the five newest stories across all feeds. Each headline opens the original story in a new tab. There is no separate Signal reader; Home has no feed or story-count setting.

The card reads stories through the authenticated Home RSS proxy. A connection failure returns an explicit unavailable response. The card keeps its most recent stories in the page until the page is reloaded.

## Steps

1. Sign in with an administrator account and scroll Home to **Studio admin**. **Signal news** lists the newest stories.
2. Read the card's state before opening a story. **Live feed** means the latest request succeeded; **Feed is empty** means it succeeded without matching stories. A result older than two minutes is styled stale. **Offline — showing last update** means the latest request failed but the card still has a prior successful result. **Signal is offline. No saved stories are available yet.** means this page has no successful result to show.
3. Select **Refresh** to request the newest stories immediately, or **Retry** when no last update exists. Hidden pages stop scheduled requests; returning to a visible page resumes refresh checks. If the adapter is unavailable, retry after the service recovers.
4. To test recovery, simulate adapter unavailability, confirm the explicit offline/last-update state, restore the adapter, and select **Refresh** or **Retry**. Confirm the list returns to **Live feed**.
5. To show or hide the **SIGNAL** strip and its **Announcement alerts** bell (new model releases and Codex resets), open **My Account**, find **AI announcements** under the locale settings, switch it on or off and select **Save Account**. It is off for every account until its owner turns it on, except a few accounts an administrator has set up; your own choice always wins. Turning it off does not touch the Signal Home card, the operational notification bell, or the alerts already read in this browser. The switch is shown only when your role may use the Signal tool.

## Expected result

The Home card requests the five newest stories and labels fresh, stale, empty and offline states distinctly. A successful refresh replaces the displayed result and updates its age.

## Troubleshooting

- **Signal says offline with a last update.** The current request failed, but the page still has its last successful stories. Select **Refresh** once after checking that the adapter has recovered; repeated clicks do not repair the service.
- **Signal is offline with no saved stories.** This page has not completed a successful story request. Restore the RSS adapter or ask an administrator to verify the configured service, then select **Retry**.
- **The SIGNAL strip or its bell is missing.** Open **My Account** and check **AI announcements**; the strip is off until you switch it on. If the switch is not shown, your role does not include the Signal tool; an administrator can add it to the role's Home tools.
- **Feed is empty.** The request succeeded but the adapter returned no stories. This is different from an offline response; check the feeds on the RSS adapter.
