# Usage analytics and your privacy

## Before you begin

The Mothership records how its features are used so the team can see what works and where people get stuck. Every admin page carries a one-line notice at the bottom with a link to **What is collected and how to opt out**, which opens the same settings described here. You need no permission to read the notice or to opt out. The dashboards themselves need a role with the usage analytics permissions described under **Who can see the dashboards**.

What is recorded:

- **Staff.** Which features you use (search, filters, previews, downloads, uploads, sharing, optimize and convert), on which file or folder, when, and whether it worked. A preview counts once it has been on screen for at least a second.
- **Shared links.** Visits, previews and downloads. A visitor is counted with a key made from the link, the first three parts of their network address and their browser family, mixed with a secret that changes every day and is never stored. No cookie is set, and the key cannot be followed from one day to the next.
- **Search words.** Kept apart from everything else, only for staff, for 30 days, and only when they do not look like an email address, phone number, web address or ID, and do not match the name of a file the person who searched cannot see.
- **Pages and device type.** Which kind of page was opened (for example Home or the Library, never its address). Every event also records whether the device is a phone, tablet or desktop, and a broad window-width band such as 1024–1439 pixels. Exact screen sizes and browser details are not recorded.
- **Never recorded.** What you type in any other field, page addresses, file contents, or anything from a site outside The Mothership.

How long it is kept: individual events 90 days, daily totals (which name no person) 25 months, search words 30 days.

## Steps

1. Select **What is collected and how to opt out** at the bottom of any admin page.
2. Read **Your setting**. It says whether analytics is on for you, off because you opted out, or limited because your browser sends Do Not Track or Global Privacy Control.
3. To stop being recorded, select **Opt out of usage analytics**. To start again, select **Opt back in**.
4. To view the dashboards, open the account menu, then **Configuration** > **Usage analytics**. Use the buttons at the top to switch between **Asset engagement**, **Workflow friction**, **Feature adoption** and **Session journeys**, and between the last 7, 30 and 90 days.
5. On **Feature adoption**, choose a **Role** and select **Apply** to see one team's use.
6. On **Session journeys**, select **View steps** to open one session's steps in order.

## Expected result

After opting out, your next action is counted only in totals that cannot be traced to you, on every device, and no collector runs in your browser. A browser sending Do Not Track or Global Privacy Control gets the same treatment while it does. People opening a shared link see a one-line notice under the page, and their browser's signal is honoured the same way.

Who can see the dashboards:

- **Usage analytics dashboards** (`dam.reports.view`) opens Asset engagement, Workflow friction and Feature adoption.
- **Usage analytics session journeys** (`dam.reports.journeys`) opens Session journeys. Sessions show a pseudonym such as "User 7f3a".
- **Identify people in session journeys** (`dam.reports.identify`) shows account names instead of pseudonyms.
- None of these is a Sandbox default. Every dashboard counts, sums and names only files and folders the viewer can open now. A file the viewer cannot see is left out of every figure, as if it did not exist.
- A search word is shown only when at least 3 different people searched for it. With one role selected on Feature adoption, any figure covering fewer than 3 people shows as "fewer than k".

How things are counted:

- A refresh of a shared link's page within 30 minutes is one visit, and the link's view count in **Shared Links** follows the same rule. A re-download of the same file within 10 minutes is one download, and a resumed or partial download is not a new one.
- An upload's retries are attempts at the same upload. Upload completion is the share of uploads that ended in success.
- Daily figures are recomputed every hour for yesterday and today. Days follow the server time zone.

## Troubleshooting

- **The dashboards are missing from the menu.** Your role needs **Usage analytics dashboards** under Advanced permissions. Ask an administrator.
- **Session journeys shows names for some people and pseudonyms for others.** Only staff accounts can be named. Shared-link visitors always show as "Visitor" with a code.
- **A file you know was viewed does not appear.** You may not be able to open it now, or the person who viewed it opted out or sent Do Not Track. Their views appear only under **Count-only loads**.
- **Today's numbers look low.** Daily figures are recomputed every hour, so the last hour may not be counted yet.
- **Someone asks for their data to be deleted.** An administrator runs `php artisan dam:analytics:forget --admin=<id>` on the server. It deletes that account's events and search words and recomputes the affected days. Add `--dry-run` first to see the counts.
- **Old data needs removing early.** `php artisan dam:analytics:prune` runs every night and applies the retention periods above; `--dry-run` shows what it would remove.
