# Home calendar

Added 2026-11-10. A Home widget (`calendar`) that puts every event, game and campaign on one month grid, so the
files made for an event are linked to it and the work around it has an owner.

On the fixed Home page (2026-11-10) the calendar shows in two places: **Your day** lists the signed-in person's
tasks and the next events as rows drawn by `home.js` from `/calendar/mine` and `/calendar/events`, and **Open the
full calendar** opens the month grid described here in a dialog. `MothershipHomeCalendar.create()` also returns
`open(id)` (open one event) and `show(filter)` (switch the filter) for those rows.

## What is on it

Every entry is added here with **Add event**: an event (one date) or a campaign (runs across dates). Tasks and
assets go on either kind, and both are edited here.

Times are stored and shown as wall-clock time with no zone, which is how camera EXIF gives them. A timed start that
is not a written date and time (`2026-11-11T17:00`) is rejected; an end that is not one leaves the entry without an
end.

## No importer ships

Until 2026-11-17 the calendar also carried two read-only feeds, an organization's public calendar and its
athletics season schedules, copied in hourly by `dam:calendar:sync`. Both were specific to one organization and were
removed with the rest of that coupling; the calendar is now a calendar people keep themselves.

`dam_calendar_events` still has `source`, `external_id` and `synced_at`, reserved for an importer. Nothing ships
that writes them, so every row is `manual`. An importer added later matches its own rows on `(source, external_id)`
and must decide for itself what to do with an entry that has left its feed but has tasks or assets hanging off it.

## Tasks and people

A task belongs to an entry and may have sub-items (one level). Each carries a free-text role ("Shooting",
"Designing"), an optional due date, a status (`open`, `in_progress`, `done`) and an **optional** assignee. Anyone who
can use the widget can add a task, give it to anyone, take it themselves ("Take it") or leave it unassigned. Nothing
about an assignment is fixed: it can be changed or cleared at any time. The **Mine** and **Needs someone** filters
show the entries with the signed-in person's unfinished tasks and with unassigned ones.

## Assets

**Add work** on a task, a sub-item or the entry itself either links files already in the Library or uploads new ones.
An upload goes through the normal Library upload (`admin.dam.assets.upload`) into a folder the person picks (remembered
in that browser), and the new assets are then linked. Linking never copies or moves a file.

Access follows the Library: only an asset the person may see can be linked, a link to an asset someone may not see is
counted for them ("1 you can't see") and never described, and unlinking it answers 404. Deleting a task keeps its files
linked to the entry; deleting an asset removes its links.

## Access

The widget needs `dam.assets`, and a custom role needs `calendar` among its Home widgets (the widget-access menu).
Everyone with the widget can add entries, tasks and links. An entry is edited by anyone with the widget and deleted
by its author or a full-access account.

## Code

- Tables: `dam_calendar_events`, `dam_calendar_tasks`, `dam_calendar_assets` (`dam_events` is the analytics log).
- `Http/Controllers/HomeCalendarController`, routes under `/admin/home/calendar` in `Routes/home-routes.php`.
- `Resources/assets/js/home-calendar.js` (mounted by `home.js`), styles at the end of `Resources/assets/css/home.css`.
- Tests: `tests/Feature/HomeCalendarTest.php`.

## Not built yet

- TODO 2026-11-10: notify a person when a task is given to them (the operational bell).
- TODO 2026-11-10: show an asset's events on its Library details page, and link from there.
- TODO 2026-11-10: a planned post date and channel per campaign item, with a "ready to post" queue.
