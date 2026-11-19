# Tools page and the tool frame

Tools is a row in the rail (on a phone it is under More). It lists every tool the signed-in account may open, and a
tool opens in the tool frame: a page of its own with one top bar that is the same for every tool.

## The Tools page (`/admin/tools`)

- One tile per tool: its icon, name, one line, and when this account last used it.
- The list is `HomeWidgetRegistry::tools()`: each entry of `dam-home.widgets` with `kind` `tool` and an `entry`, kept
  when the role list and the permission allow it (the same decision as Home's catalog).
- Order, icon and the day a tool arrived are in `dam-home.tools.tiles`. A tool missing from that list goes last with
  a spanner.
- The footer says, in this order of precedence:
  - **Needs set-up**: the tool names a setting in `requires_config` and it is empty. Only a full admin
    (`permission_type` `all`) gets this tile. It is not a link, and its line is the tile's `setup` text.
  - **Used today**, **Used yesterday**, **Used Fri 13 Nov**, **Used 13 Nov 2025**: the day in the account's timezone.
  - **New**: never opened, and the tile's `added` day is less than `dam-home.tools.new_days` days ago.
  - **Never used**.
- A use is written by `HomeToolController::file` when it serves a tool's entry page, so opening a tool from Home's
  shelf counts as well. Table `dam_home_tool_uses`: one row per account and tool, `last_used_at`.

## The tool frame (`/admin/tools/{tool}`)

- The page has no rail: a top bar (Back, the tool's name, Reload) and the tool in a frame below it. The tool is still
  the page `HomeToolController::file` serves, and the two still talk through the `mothership-widget/v1` messages.
- `tool-frame.js` adds the frame after the app has mounted, and owns leaving:
  - Back and Reload ask first only when work may be lost: the tool reported work, or its current page never promised
    to report it. A tool that has not opened never asks.
  - The question is a band under the bar with Cancel and "Discard and close" (or "Discard and reload"). It is never
    `window.confirm`.
  - Escape never closes a tool by itself: the first Escape asks, a second takes the question away.
  - While the tool holds reported work, closing the tab asks through the browser too.
- Back goes to the page the person came from when that was another page of the app, and to Tools otherwise. The
  button's name says which.
- Opening, slow (after 30 seconds) and failed each say so where the tool will be. Slow and failed offer Reload and
  Back; when the tool failed, Reload is the filled button. Every try at loading the tool gets its own 30 seconds:
  the first, a Reload, and a Reload after that.
- A file a tool opens in its own place (a picture, a PDF) stays in view. A band under the bar says what happened and
  its Reload brings the tool back; nothing is laid over the file. Nothing asks about work then: the tool's page went
  when the file came. The band steps aside while a question is up.
- A tool whose page marks itself `data-agh-ephemeral` (Palette Studio saves every edit as it happens) never reports
  work, so leaving it never asks.
- A sheet the tool opens for a press on the bar (an item of the saved-sets menu, Export, Save to Library) gives the
  focus back to that control when it closes, as a dialog of the page itself would; for the menu's items that is the
  menu's button. The bridge (`home-frame.js`) marks a sheet that opens while the tool is still answering a command
  from the bar (`fromBar` on its `sheet` message), and the page moves the focus only while it is still in the tool. A
  sheet the tool opens from its own control gives the focus back to that control, inside the tool.
- A role that may not use a tool gets 403 from the frame page; an id that is not a tool with a page gets 404.

## Adding a tool

Add the widget to `dam-home.widgets` as before (see [Home](customizable-home.md)), then add a line to
`dam-home.tools.tiles` with a glyph from `admin::bridge.icon` and, for a new tool, the day it arrived.

## Not built yet

The frame's saved-sets menu, Export and "Save to Library" belong to the tools' restyle into the frame, one change per
tool. Home still has its own Tools shelf, which opens a tool in a panel over Home.

## Checks

`packages/Mothership/DAM/tests/Feature/ToolsPageTest.php` covers who gets which tile, what each footer says over
time, and who may open a frame. `tools/browser/tests/tool-frame-dom.test.cjs` covers Back, Reload, Escape, the
question, a tool that did not open, a file in the frame, a load that stalls, the bar and where the focus goes when a
sheet it opened closes, in a browser. Its page is the frame
view as Laravel renders it (`tools/browser/fixtures/blade/tool-frame.html`) under the committed admin stylesheet;
after changing `tools/frame.blade.php`, regenerate the fixture with
`BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php`.
