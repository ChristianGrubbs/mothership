# Create and reuse images with StylePress

## Before you begin

Open StylePress from Home. You can browse the live style catalog even when generation is not connected: search by style or use case, filter by category or aspect ratio, switch among **All**, **Favorites**, **Recent** and **Needs slots**, or sort the cards. On a desktop the catalog shows large cards (about four across at full width) by default; the **Large / Compact / List** toggle changes the card size (the choice is remembered in this browser), and **Group by category** (on by default when no search or filter is active) arranges the cards under sticky category headers, each with a count and a **Show only** shortcut. Cards load in batches as you scroll, so the catalog stays quick as styles are added. With a card focused, the arrow keys move between cards and Enter selects one; **Clear filters** appears whenever a search, category, aspect ratio or tab narrows the list. The catalog and its category totals can change, so use the counts shown in the current page rather than expecting a fixed number. On a narrow window or a phone the request form is the page: **Change style** opens the catalog as a sheet, and picking a style closes it.

The Higgsfield balance shown in StylePress belongs to the shared work account. It is not a personal credit allowance. Jobs, uploaded references and saved outputs remain account-owned inside Mothership: another signed-in account cannot inspect your pending job or its result merely because both accounts use that shared provider balance.

## Steps

1. Select a style. The star saves a browser-local favorite, while **Recent** records styles selected in this browser. Read the style look, slot contract and any source-note or style-pack attachments. A reference-only entry can be browsed and copied but cannot generate.
2. Enter the asset request and choose **Create new** or **Restyle a source**, an output aspect ratio, a model (**GPT Image 2.5 · Flare** is the default; **GPT Image 2.5 · Sunburst** and **GPT Image 2 · Standard** are also available), quality and resolution. Restyle requires at least one reference. Add as many as eight permitted Mothership images or local PNG, JPEG or WebP files; each file must be no more than 32 MB and 32 megapixels. **From The Mothership**, under References, opens a full-size browser window over the tool that works like the library: the left column lists **All images**, **Added in the last 30 days**, **Favorites** and the folder tree you are allowed to see (expand a folder with the arrow, click it to show its images; only PNG, JPEG and WebP images are listed, because those are the formats a reference can use). The right side has search (as you type, across the whole permitted library, or your favorites when that entry is selected), a sort (newest or name), a breadcrumb for the current folder and a grid of square thumbnails with **Show more** paging. Click images to select several at once (the footer counts free reference slots), then choose **Add selected**; images already attached are dimmed, marked **Added** and cannot be added twice. **Cancel**, the close button, the Escape key or a click outside the window closes it without adding anything. The counter under the reference label reads the references in use out of eight.
3. Optionally select **Enhance request**. While the enhancement runs, **Generate** and **Estimate credits** stay disabled. The finished wording is accepted automatically and Generate uses it; edit it in place (then choose **Use this text**, or let Generate ask once whether to use the edited text) or choose **Discard** to keep the original request. Changing the request, style, mode or references afterwards keeps the enhanced text but marks it stale with an **Enhance again** button. Enhance needs a written request first, and Restyle needs a source image before enhancing. The request box holds up to 5,000 characters and shows a live count; Enhance accepts up to 4,000, and the page says so before sending anything.
4. Select **Estimate credits** to inspect the current provider estimate. When ready, select **Generate**. Generation performs its own fresh estimate and then submits one request. The page saves the returned account-owned job and polls its Mothership status; a queued or running job survives reload, and the same browser resumes checking it. The server continues processing if the browser closes.
5. Wait through generation and the separate **Image completed. Saving to Mothership…** phase. On success, StylePress automatically imports the output as a DAM asset; there is no separate manual import button. The result panel reports **Saved to Mothership · Archived to the vault** or **Saved to Mothership · Vault archive pending device sync**. Select **Open generated image ↗** for the saved output or **Generation details** for its model, provider, settings, original/enhanced/full prompts, saved style snapshot, references and reproduction attachments. From those details, **Reuse in StylePress** restores the recorded style, settings, text and available references; if the catalog changed, it preserves the historical style snapshot.

## Expected result

A successful generation becomes an Mothership asset before the result link is offered. Its **Generation details** page is the durable generation history for that asset, including its provenance and reusable prompt/style data. Archive state is shown in the immediate StylePress result panel, not on the durable details page.

The shared balance can be visible to every authorized StylePress user, but a job lookup is filtered by the signed-in Mothership account. Account B receives no view of account A's job, saved output endpoint or uploaded reference. Opening the generated image uses the account-owned saved output; the current StylePress panel does not offer a second import choice or a dedicated download button, though normal browser save controls can act on the opened image.

## Troubleshooting

- **Generation is not connected yet.** You can still browse styles, save favorites and use **Copy prompt**, which takes Generate's place as the filled button. Ask the administrator to restore the configured StylePress generation connection before estimating, enhancing or submitting.
- **Settings changed. Estimate again before generating.** Run **Estimate credits** again if you want an updated preview. **Generate** also obtains a fresh estimate immediately before its one submission.
- **Status is temporarily unavailable or processing exceeds the page window.** Keep the saved job and select **Check again**, or reload the same browser to resume polling. Do not start a replacement merely because a status check failed; the provider job may still be running.
- **Generate returned an ambiguous provider error before a job appeared.** StylePress does not automatically resubmit. Do not repeatedly select **Generate** until an administrator has checked the shared provider account for a request that may already have been accepted.
- **A reference is unavailable.** Remove and upload it again. Only references owned by the signed-in account and permitted DAM images can be used; another account's reference or job returns unavailable.
- **The image completed but is still saving.** The provider result and Mothership import are separate phases. The worker retries saving the same completed result without submitting or charging for another generation.


## Enhance request

**Enhance request** rewrites your brief into a production-ready request for the selected style. When the fast enhancer is enabled by an administrator (`STYLEPRESS_ENHANCE_DRIVER=groq` with a Groq key), text-only briefs are rewritten by `openai/gpt-oss-120b` and briefs with reference images by the vision-capable `qwen/qwen3.8-27b`, usually within a few seconds; the first three reference images are sent, downscaled. If the fast enhancer is unavailable or rate limited, the shared Higgsfield enhancer is used automatically, so the button always works the same way. Enhancement never invents brands or claims and keeps slot placeholders such as `{BRAND_NAME}` exactly as written; the complete style template is still applied at generation time.


## Generating and viewing the result

After **Generate** a spinner and a status line show the estimate, the submission, generation and saving steps; you can close the browser and come back, the server keeps working. A failure shows in the same line, marked with ✕. When the image is saved it appears at the top of the request form at full width. **View full size** opens it in a lightbox over the tool (click the image or press Escape to close), **Open in new tab** shows the raw file, **Download** saves the original, and **Generation details** opens the library page for the asset, which now shows the generated image above the model, settings, prompts and reference media.


## Source links

When a reference comes from the library, the saved generation is linked to it: the generated asset shows a **derived from** relationship to each source image, and each source image lists the generated asset in return. Re-saving the same generation never duplicates the link; a source that has since been deleted is skipped. Uploaded local files have no source link.

## Saved titles

The saved asset receives a short title written by the configured Magic AI OpenAI platform from the style and your request (4 to 10 words, no line breaks). When no text platform is configured or the call fails, the title is the style name followed by the first sentence of your request, on one line and at most 120 characters. The complete request, enhanced request and settings stay in the generation details sidecar. Set `STYLEPRESS_TITLE_MODEL` to pin a specific text model.
