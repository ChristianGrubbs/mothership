# The Mothership menu bar app: direction

## Audience and idea

- **Who:** the operator, glancing at the menu bar while doing other work.
- **First glance must answer:** is the site up, and do I need to do anything?
- **Brief:** start at login, show health and basic stats from a custom menu bar icon, control the site from there, and match the site's split-flap design and theming.
- **Idea:** the panel is a small piece of the Bridge. Home's arrivals board becomes the app's status board, and the yellow deck that joins it announces what is new.
- The operator named the object, so no concept shortlist was run.

## Reference kit

The source is the accepted redesign, `redesign/v2-bridge/`.

| Source | What it gives |
|---|---|
| `out/home-dark-1024.png`, `out/home-dark-390-first-screen.png` | The board, the joined yellow deck, the theme segment |
| `src/v2.css` (`.board`, `.frow`, `.lamp`) | Board `#0E0E0E`, cells `#232323` over `#1B1B1B`, seam `#050505` behind the glyph, ink `#EDEDED`, seen `#9A9A9A`, dim `#8C8C8C`, blank gap cells, halo lamps |
| `assets/motion/motion.js` `flapRow`, `motion-tokens.css` | 55 ms a glyph, 3 to 6 passing glyphs, 6 ms a column, each cell squashing to 82% once per step |
| `direction.md` | Grounds, radii, type roles, colour jobs |
| `assets/fonts/` | Instrument Sans and Martian Mono (both OFL), bundled with their licences |

## Surface

- macOS 27 menu bar item with a 400 pt wide panel. Pointer and keyboard.
- Job: read the site's health and start, stop or restart it.
- Mode: one-off. Reference intent: the product's own design system.

## Glyph states

| State | Glyph | Second cue beyond colour |
|---|---|---|
| Online | Full M, all three modules | Complete shape |
| Offline | Dimmed M, no modules | Modules absent |
| Changing | Full M, one module lit, stepping cell to cell | Motion |
| Changing, Reduce Motion | Full M, two side modules lit, centre empty | Empty centre |
| Needs attention | Full M, one red module at the V, side modules absent | One module instead of three |
| Checking | Dimmed M, centre module only | Dimmed ink and a single module |

## The board

Dark in both themes, one flap cell per character, as on Home.

- **Header bar:** "Ship status" on the left. On the right, the blue live lamp when online, then when the stack was last checked.
- **Headline strip:** 16 large cells carry the state, like the sign-in page's "WELCOME ABOARD" strip. While the stack changes, the elapsed time sits at the right end and flips each second.
- **Column headings:** Service and Status, in dim capitals.
- **Service rows:** 25 cells: 10 for the name, one empty gap, 14 for the status. Site, Containers, Tunnel, Bridges, Ollama, ArtCraft.
- **ArtCraft row:** the whole row opens the ArtCraft page, and its last cell is `>`.
- **Too long:** text ends in `…`. The full text is in the row's screen-reader sentence and the reason line.

| Row state | Lamp | Status ink |
|---|---|---|
| Healthy | Small blue square, no halo (the headline lamp keeps its halo) | White |
| Failing | Red circle with halo | Red |
| Off, expected | Hollow ring | Grey, whole row |
| Checking | Dark grey circle | Grey, whole row |
| New (ArtCraft updates) | Yellow circle with halo | Yellow |

## The yellow deck

When ArtCraft has updates and the stack is not changing, a `#FFD60A` deck joins the bottom of the board as one object: the count, large, "ArtCraft updates", and one blue Review button. With nothing new there is no deck.

## Motion

- Home's flap, exactly: a cell shows each passing glyph for 55 ms and squashes to 82% once per step. 3 to 6 passing glyphs, chosen deterministically, then the final one. Columns 6 ms apart.
- Rows are 60 ms apart, not Home's 80, so that eight rows still arrive inside Home's one-second limit.
- Blank cells stay blank. Only characters flip.
- **When it runs:** the board flips in when the panel opens or the page changes, and a cell flips when its value changes while the panel is open. Unchanged cells stay still.
- **When it does not run:** nothing draws while the panel is closed, and the display clock stops when the last cell lands.
- **Reduce Motion:** final text at once, no flip.
- The live lamp breathes only while the stack is changing. Controls never wait for the board.

## Theming

Two themes, named as on the site: Graphite (default) and White. A segment in the header switches them and the choice is remembered. A theme changes colour values only. Nothing moves.

| Part | Graphite | White |
|---|---|---|
| Deck | `#0B0B0B` | `#EFEFEF` |
| Cards | `#161616`, top inner highlight | `#FFFFFF`, one-pixel shadow |
| Board | `#0E0E0E` | `#0E0E0E` |
| Yellow deck | `#FFD60A` | `#FFD60A` |

## Panel states

| State | Headline | Strongest control | Other controls |
|---|---|---|---|
| Checking | Checking | None | Disabled until the first reading |
| Online | Online | None, nothing is needed | Restart, Stop (quiet) |
| Offline | Offline | Start | None |
| Needs attention, stack at fault | Needs attention | Restart | Stop (quiet) |
| Needs attention, outside the stack (Ollama, tunnel) | Needs attention | None, a restart would not fix it | Restart, Stop (quiet) |
| Drive access refused | Needs attention | Open Privacy Settings | None |
| Starting, Stopping, Restarting, Updating | Verb in progress, with the clock | None | Progress only; closing the panel is safe |
| Busy | Busy | None | Controls disabled while another session changes the stack |
| Confirm stop | Stop the site? | Stop site (red) | Cancel |
| Confirm restart | Restart the site? | Restart (blue) | Cancel |
| Action failed | Start failed | Try again | Open log |
| Not set up | Not set up | None | Open log |

The yellow deck's Review button is blue too. It sits apart from the stack's controls and only opens a page.

## Storage block

The Umbrel is where assets live, so its figures lead. This Mac's space is one labelled sentence.

| State | Shows |
|---|---|
| Read | Space used by The Mothership, and space free of the drive's total |
| Drive read, storage service down | Free space, with the library size marked unavailable |
| Reading | "Reading the Umbrel" |
| Unreachable | "Umbrel unreachable", with a warning sign. This Mac's figure never takes its place |
| Not connected | "Umbrel not connected", and what to run |

## ArtCraft page

The page shows whether ArtCraft apps have updates, installs them on demand and restarts the MCP server. Engine updates stop the site automatically.

The page has its own board: ArtCraft as the headline, then Apps, Engines and MCP server.

| State | Strongest control | Other controls |
|---|---|---|
| Updates available | Update Apps | Update Engines, Restart (quiet) |
| Engine updates only | None, because they stop the site | Update Engines, Restart (quiet) |
| Up to date | None | Restart (quiet) |
| Confirm engines | Stop Site and Update (red) | Cancel |
| Confirm server restart, work in progress | Restart Anyway (red) | Cancel |
| Updating | None | Progress only |
| Update failed | Try Again | Open Log |
| Checking, no earlier result | None | None |
| Check failed, no earlier result | Check Now | None |
| Check failed, earlier result kept | As above | "Last check failed" in the footer |

An out-of-date version is bold and carries an arrow, so the difference never rests on weight alone.

## Accent jobs

- Blue acts: the primary control and the live lamp.
- Red needs you: failures and the destructive confirm.
- Yellow means new: ArtCraft updates, and nothing else.
- No green: success is a word and a lamp, as the brand tokens require.

## Layout

One column on the deck, 8 pt from its edge: header, board with its yellow deck, reason sentence, one card of figures and storage, controls, footer. During a change, thirteen square modules, one per container, replace the figures. Radii nest: panel 26, board and cards 18, controls 12.

## Type

- Instrument Sans for reading: reasons, buttons, sentences.
- Martian Mono for flap cells, figures, times, versions and capital labels.
- Figures 17 pt, the largest text in their card. Labels 10 pt capitals.

## Tools

- Panel and glyph: SwiftUI and AppKit. The board is one Canvas.
- Icons: SF Symbols, the platform's own set.
- Review: `--render-states` writes every state, White and Graphite, to PNG, and a filmstrip of the flip.

## Appendix: exclusions

- Cream, beige or warm grey. Grey panels around regions. Outlined boxes. Scattered small coloured badges.
- Tinted status pills, coloured left borders and translucent chrome.
- Colour as the only signal of any state.
- Any animation that runs while nothing is changing.
