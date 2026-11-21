<?php

/*
 * P2 S2 access matrix: for every `bytes` and `listing` route in route-inventory.php, the test that probes it with a
 * viewer who may not see the asset or folder (a refusal, or a listing that leaves it out), or the slice that owes
 * that probe. RouteInventoryTest fails on a bytes or listing route with neither, on a probe whose test is gone or
 * no longer names the route, and on an owed probe for a slice that has shipped.
 *
 * A probe is [test file under packages/Mothership/DAM/tests, the it() description].
 */

$hiddenAssetEndpoints = ['Feature/AssetHiddenEqualsMissingTest.php', 'answers a folder-visible but file-private asset like a missing one on every per-asset endpoint'];
$hiddenAssetRest = ['Feature/AssetHiddenEqualsMissingTest.php', 'answers a hidden asset like a missing one on the REST API'];
$hiddenAssetP2s1 = ['Feature/AssetHiddenEqualsMissingTest.php', 'answers a comment, property or former 403 route on a hidden asset like a missing one (P2 S1)'];
$hiddenAssetRestP2s1 = ['Feature/AssetHiddenEqualsMissingTest.php', 'answers a comment or property on a hidden asset like a missing one on the REST API (P2 S1)'];
$hiddenAssetLibrary = ['Feature/AssetTest.php', 'refuses a folder-visible but file-private asset everywhere the Library hides it'];
$p1bReports = ['Feature/AnalyticsReportsTest.php', 'never counts or names a hidden asset\'s events in any report'];
$hiddenFolderEndpoints = ['Feature/DirectoryHiddenEqualsMissingTest.php', 'answers a folder the viewer cannot see like a missing one on every folder-id endpoint'];

// P2 S3: a non-editor member under grants, a folder-hidden asset or folder and a draft and an expired asset in a
// granted folder; each it() covers one family of routes.
$s3AssetBytes = ['Feature/AvailabilityAccessProbesTest.php', 'answers a hidden or unavailable asset like a missing one on the editor resource, generation file and attachments zip'];
$s3HomeBytes = ['Feature/AvailabilityAccessProbesTest.php', 'answers another admin\'s StylePress, vectorization and tool files, and any asset id or path, like missing ones'];
$s3Folders = ['Feature/AvailabilityAccessProbesTest.php', 'leaves hidden folders and hidden or unavailable assets out of every folder-tree listing and count'];
$s3Library = ['Feature/AvailabilityAccessProbesTest.php', 'leaves hidden and unavailable assets out of the Library grid, selection count, filter options, picker and linked resources'];
$s3Shares = ['Feature/AvailabilityAccessProbesTest.php', 'leaves links to hidden and unavailable assets and folders out of the Shared Links page and the share dialog'];
$s3Home = ['Feature/AvailabilityAccessProbesTest.php', 'leaves hidden and unavailable assets out of Home recent, totals, favourites, the StylePress browser and the vectorization list'];
$s3Rest = ['Feature/AvailabilityAccessProbesTest.php', 'leaves hidden folders and hidden or unavailable assets and links out of the REST directory, share and tag listings'];
$homePlaque = ['Feature/HomePlaqueTest.php', 'lists only plaque sets the account may see in the Library'];
$homeCalendar = ['Feature/HomeCalendarTest.php', 'never links, lists or unlinks an asset outside the account folder grants'];
// The file is a person's own download from the Studio video service, never an asset: another account's job id answers 404.
$homeVideo = ['Feature/HomeVideoDownloaderTest.php', 'passes the service\'s own answers through, including a full backlog and a job that is not yours'];
$s3NoAssets = ['Feature/AvailabilityAccessProbesTest.php', 'cannot enumerate a hidden or unavailable asset through the tag vocabulary or the groups page'];

// P2 S3 review: every unavailable state (draft, pending, archived, scheduled, expired) against a missing id, under
// grants and lists, for a member who may open the file's folder or list but not manage availability.
$s3rBytes = ['Feature/AvailabilityReviewProbesTest.php', 'answers a draft, pending, archived, scheduled or expired file like a missing one on downloads, previews and thumbnails'];
$s3rZip = ['Feature/AvailabilityReviewProbesTest.php', 'leaves a draft, pending, archived, scheduled or expired file out of a folder zip'];
$s3rExplorer = ['Feature/AvailabilityReviewProbesTest.php', 'leaves an unavailable file out of the explorer listing and answers it as a Find-similar seed like a missing one'];
$s3rRestDownload = ['Feature/AvailabilityReviewProbesTest.php', 're-checks a REST download link when it is used, so one issued before the file became unavailable answers like a missing file'];
$s3rShares = ['Feature/AvailabilityReviewProbesTest.php', 'leaves links to unavailable files out of the Shared Links page and REST share list for a custom role that reaches every folder'];
$s3rAggregates = ['Feature/AvailabilityReviewProbesTest.php', 'counts only files the viewer can list in tag counts, the relationship and file badges and the folder delete answer'];
$s3rDerived = ['Feature/AvailabilityReviewProbesTest.php', 'closes a StylePress reference or vectorization result once its Library source is no longer out for the viewer'];

// P2 S4: the S3 matrix above meets a trashed asset too; these two add a full admin, who sees every live asset.
$trashedAssetEndpoints = ['Feature/AssetHiddenEqualsMissingTest.php', 'answers a trashed asset like a missing one on every per-asset endpoint and the REST API, to a full admin too (P2 S4)'];
$trashedLibrary = ['Feature/TrashTest.php', 'answers a trashed asset with 404 on its per-asset routes and leaves it out of listings and counts, for a full admin too'];
$trashedZipsAndSearch = ['Feature/TrashTest.php', 'keeps a trashed asset out of folder and selection ZIPs and out of keyword and semantic search, for a full admin and a member, until Restore'];

// P4: Library organization. A member under grants; a file in a folder they cannot open is labelled, on a board, pinned
// to and matched by a section, favorited and matched by a saved search, and is left out of every listing and count.
$p4Organization = ['Feature/OrganizationApiTest.php', 'leaves a hidden file out of every organization listing and count, on the web and over REST'];
// P6a: a batch's progress names each file and links its output only while the requester can still see the file.
$p6Batch = ['Feature/BatchProcessingTest.php', 're-checks access when the job runs: an asset hidden or trashed since enqueue is skipped and left untouched'];
$p6BatchRest = ['Feature/BatchProcessingTest.php', 'answers REST like the Library: same recipes, same batch, same retry and cancel'];
// P6 D26: the Embed dialog's list of an asset's embeds, and the Web MP4 variant of the Library download.
$p6EmbedList = ['Feature/VideoEmbedTest.php', 'makes an embed only for someone who can see the asset and holds the embed permission'];
$p6EmbedListRest = ['Feature/VideoEmbedTest.php', 'answers a hidden asset like a missing one on the REST embed routes'];
$p6WebMp4 = ['Feature/VideoProxyRetryTest.php', 'refuses Web MP4 of a hidden asset exactly like a missing one'];
// P4 D2: a collection's audience reads members only through the collection's routes; a stranger, a non-member file
// and a member that is not out for use answer like missing ones, and the same file through the Library, search, zip
// and REST stays hidden from the audience.
$p4cServed = ['Feature/CollectionAccessTest.php', 'serves an audience member a member only through the collection, and answers 404 for it through the Library, search, zip and REST'];
$p4cStranger = ['Feature/CollectionAccessTest.php', 'answers a collection the viewer is not in, and an asset that is not a member, like missing ones'];
$p4cUnservable = ['Feature/CollectionAccessTest.php', 'neither serves nor counts a withdrawn, pending, expired, scheduled, trashed or merged member'];
$p4cRest = ['Feature/CollectionApiTest.php', 'answers a stranger 404 like a missing collection, an audience member 403 on changes, and refuses redistribution'];
$p4cRestClosed = ['Feature/CollectionApiTest.php', 'serves a closed collection to its audience as 410 over REST too, and never lists an unservable member'];

// P5b (D18, D19): a member granted one folder, against a file and a folder they cannot see.
$p5bWeb = ['Feature/AutomationRulesTest.php', 'leaves hidden files and folders out of every automation and mapping listing'];
$p5bRestRules = ['Feature/AutomationApiTest.php', 'manages, dry-runs and runs a rule over REST, with its run log'];
$p5bRestMappings = ['Feature/AutomationApiTest.php', 'manages, dry-runs and runs a mapping over REST, seeing only files the caller may list'];

// P8′ (D25): templates. A member under grants: a hidden Library image in a slot or a preview answers like a missing
// id, a filler sees only published templates and their published images, and the uses count goes through the seam.
$p8Images = ['Feature/TemplateAssetCreationTest.php', 'accepts only Library images the filler can open: a hidden id is refused exactly like a missing one, and uploads are refused'];
$p8Resources = ['Feature/TemplateAssetCreationTest.php', 'keeps template images and previews to what the viewer may see'];
$p8List = ['Feature/TemplateAssetCreationTest.php', 'separates manage from use and needs upload rights on the destination folder'];
$p8Rest = ['Feature/TemplateAssetCreationTest.php', 'answers REST like the web: same list, same template, same refusals and the same lineage'];
// P5a: upload requests a viewer may not see (another member's, under either access model) and the guest files they hold.
$p5aRequests = ['Feature/GuestUploadReviewTest.php', 'lists only the upload requests the viewer may see, in the Library and over REST'];
$p5aSubmissions = ['Feature/GuestUploadReviewTest.php', 'lists only the submitted files the viewer may see, and counts the rest, in the Library and over REST'];
$p5aPending = ['Feature/GuestUploadIsolationTest.php', 'keeps a pending guest file out of the Library, search and REST for other staff until it is accepted'];

// Creative Hub (ADR 0012): the workspace page hands out a file's download URL, so a hidden file must answer like a missing one.
$hubResourceList = ['Feature/CreativeHub/HubResourceAccessTest.php', 'lists only the resources the viewer may read on admin.dam.hub.resources.list'];
$hubResourceBytes = ['Feature/CreativeHub/HubResourceAccessTest.php', 'answers a resource the viewer may not read like a missing one on admin.dam.hub.resources.bytes'];

return [
    'admin.api.dam.assets.model-thumbnail.show'         => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.api.dam.assets.files.model-thumbnail.show'   => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.api.dam.assets.model-thumbnail.source'       => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.api.dam.assets.model-thumbnail.image'        => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.api.dam.assets.files.model-thumbnail.source' => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.api.dam.assets.files.model-thumbnail.image'  => [['Feature/Api/ModelThumbnailApiTest.php', 'hides inaccessible models on every capture endpoint']],
    'admin.dam.assets.ai-rename.members'                => [['Feature/AiIndex/StackMemberRenameTest.php', 'does not leak inaccessible members or accept foreign slots']],
    // bytes
    'admin.api.dam.ai-review.index'           => [['Feature/AiTagReviewQueueTest.php', 'answers REST like the Library under api.dam.ai_review scopes']],
    'admin.api.dam.assets.download'           => [$hiddenAssetRestP2s1, $trashedAssetEndpoints, $s3rRestDownload, $p4cServed],
    'admin.api.dam.assets.show'               => [$hiddenAssetRest, $trashedAssetEndpoints, $p4cServed],
    'admin.api.dam.assets.similar'            => [['Feature/SimilarImagesTest.php', 'answers REST like the Library: list, one asset and dismiss, under its scopes']],
    'admin.api.dam.similar.index'             => [['Feature/SimilarImagesTest.php', 'answers REST like the Library: list, one asset and dismiss, under its scopes']],
    'admin.dam.ai-review.index'               => [['Feature/AiTagReviewQueueTest.php', 'lists only files the reviewer can see, needs dam.asset.tag, and groups by term or by file']],
    'admin.dam.assets.custom_download'        => [$hiddenAssetLibrary, $trashedLibrary, $s3rBytes],
    'admin.dam.assets.download'               => [$hiddenAssetLibrary, $trashedLibrary, $s3rBytes, $p6WebMp4, $p4cServed],
    'admin.dam.assets.download_compressed'    => [$hiddenAssetLibrary, $trashedLibrary, $s3rBytes, $p4cServed],
    'admin.dam.assets.edit'                   => [$hiddenAssetLibrary, $trashedLibrary, $p4cServed],
    'admin.dam.assets.editor.document'        => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.assets.editor.resource'        => [$s3AssetBytes],
    'admin.dam.assets.generation'             => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.assets.generation.file'        => [$s3AssetBytes],
    'admin.dam.assets.generation.output'      => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.assets.generation.view'        => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.assets.image_edit.bg_preview'  => [$hiddenAssetP2s1],
    'admin.dam.assets.show'                   => [$hiddenAssetLibrary, $trashedLibrary, $s3rAggregates],
    'admin.dam.collections.members.download'  => [$p4cServed, $p4cStranger, $p4cUnservable],
    'admin.dam.collections.members.preview'   => [$p4cServed, $p4cStranger, $p4cUnservable],
    'admin.dam.collections.members.proxy'     => [$p4cStranger],
    'admin.dam.collections.members.thumbnail' => [$p4cServed, $p4cStranger, $p4cUnservable],
    'admin.dam.collections.zip'               => [$p4cServed, $p4cStranger, $p4cUnservable],
    'admin.dam.directory.zip_download'        => [$hiddenFolderEndpoints, $s3rZip, $trashedZipsAndSearch, $p4cServed],
    'admin.dam.file.asset-file'               => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.file.asset-files-zip'          => [$s3AssetBytes, $trashedZipsAndSearch],
    'admin.dam.file.cover-art'                => [$hiddenAssetEndpoints, $trashedAssetEndpoints],
    'admin.dam.file.fetch'                    => [$hiddenAssetEndpoints, ['Feature/FileTest.php', 'refuses an anonymous fetch of an existing, owned file before sending any of it'], $trashedAssetEndpoints],
    'admin.dam.file.preview'                  => [$hiddenAssetEndpoints, $trashedAssetEndpoints, $s3rBytes],
    'admin.dam.file.thumbnail'                => [$hiddenAssetEndpoints, $trashedAssetEndpoints, $s3rBytes, $p4cServed],
    'admin.dam.hub.resources.bytes'           => [$hubResourceBytes],
    'admin.dam.pages.grant'                   => [$hiddenAssetEndpoints, ['Feature/WebPageAdminRouteTest.php', 'denies the grant on the directory check, not the route acl, to a viewer without a grant'], $trashedAssetEndpoints],
    // Proxies the Home tool services; reads no asset. Probed: a file another admin's session issued, a never-issued id
    // and an asset's file name all answer alike, and nothing reaches the upstream.
    'admin.dam.similar.index'                => [['Feature/SimilarImagesTest.php', 'lists, counts and hints a pair only when the viewer may see both sides']],
    'admin.home.service'                     => [$s3HomeBytes],
    'admin.home.stylepress.generation'       => [$s3HomeBytes],
    'admin.home.stylepress.output'           => [$s3HomeBytes],
    'admin.home.stylepress.reference-file'   => [$s3HomeBytes, $s3rDerived],
    // Style catalog files, addressed by slug: an asset id answers like an unknown style.
    'admin.home.stylepress.source'           => [$s3HomeBytes],
    'admin.home.stylepress.thumbnail'        => [$s3HomeBytes],
    // Bundled widget files: an asset's Library path answers like a path nothing holds.
    'admin.home.tool'                        => [$s3HomeBytes],
    'admin.home.vectorization.file'          => [$s3HomeBytes, $s3rDerived],
    // Agent REST results: only the starting account, and only while it keeps the paid tool.
    'admin.api.dam.vectorizations.file'      => [['Feature/Api/VectorizationApiTest.php', 'refuses without a bearer, without the scope, and for another account\'s conversion']],

    // listing
    // P3b-2: suggestions never name or count a member the viewer cannot list, on a fresh or a cached read.
    'admin.api.dam.assets.embeds.index'         => [$p6EmbedListRest],
    'admin.api.dam.assets.index'                => [['Feature/Api/AssetApiTest.php', 'only lists assets from granted directories for a custom-role api user'], $p4cServed, $p5aPending],
    'admin.api.dam.assets.related'              => [['Feature/RelatedAssetsTest.php', 'answers a hidden seed like a missing one over REST, and lists only what the key owner may see']],
    'admin.api.dam.batches.show'                => [$p6BatchRest],
    'admin.api.dam.collections.assets.index'    => [$p4cRest, $p4cRestClosed],
    'admin.api.dam.collections.index'           => [$p4cRest],
    'admin.api.dam.collections.show'            => [$p4cRest, $p4cRestClosed],
    'admin.api.dam.stack-suggestions.index'     => [['Feature/StackSuggestionsTest.php', 'never names or counts a member the viewer cannot see, even from a cached list']],
    'admin.dam.stack-suggestions.index'         => [['Feature/StackSuggestionsTest.php', 'never names or counts a member the viewer cannot see, even from a cached list']],
    'admin.api.dam.directory.index'             => [$s3Rest],
    'admin.api.dam.shares.index'                => [$s3Rest, $s3rShares],
    // Tags are a shared vocabulary (the Tags page manages them globally) and name no asset: probed that each entry is
    // only an id and a name, with no asset id or count.
    'admin.api.dam.tags.all'                    => [$s3Rest],
    'admin.api.dam.upload_requests.index'       => [$p5aRequests],
    'admin.api.dam.upload_requests.submissions' => [$p5aSubmissions],
    // The hidden/unavailable asset id is probed here; #135 (main) adds the grid-without-an-asset probe and fixes the
    // grid's ambiguous default sort when it merges in.
    'admin.dam.asset.linked_resources.index'    => [$s3Library],
    'admin.dam.asset_picker.get_assets'         => [$s3Library, $trashedLibrary],
    'admin.dam.assets.embeds.index'             => [$p6EmbedList],
    'admin.dam.assets.index'                    => [$s3Library, $trashedLibrary],
    'admin.dam.assets.related'                  => [['Feature/RelatedAssetsTest.php', 'never shows or counts a hidden asset, and answers a hidden seed like a missing one']],
    'admin.dam.batches.show'                    => [$p6Batch],
    'admin.dam.collections.data'                => [$p4cServed, $p4cStranger, $p4cUnservable],
    'admin.dam.collections.list'                => [$p4cStranger, $p4cUnservable],
    'admin.dam.configuration.capture.preview'   => [$hiddenFolderEndpoints],
    'admin.dam.configuration.fields.index'      => [['Feature/MetadataFieldDefinitionsTest.php', 'never counts a hidden asset in the field screen or the delete confirmation, but still archives for it']],
    'admin.dam.directory.asset_counts'          => [$s3Folders, $trashedLibrary],
    'admin.dam.directory.assets'                => [$s3Folders, $trashedLibrary],
    'admin.dam.directory.children'              => [$hiddenFolderEndpoints],
    'admin.dam.directory.descendants'           => [$s3Folders],
    'admin.dam.directory.index'                 => [$s3Folders],
    'admin.dam.directory.paths'                 => [$s3Folders],
    'admin.dam.directory.search'                => [$s3Folders],
    'admin.dam.explorer.count_items'            => [$s3Library, $trashedLibrary],
    'admin.dam.explorer.filter-options'         => [$s3Library],
    'admin.dam.explorer.index'                  => [$hiddenAssetLibrary, ['Feature/AiIndex/SmartSearchAccessTest.php', 'never returns a hidden file by words or by meaning, and counts only visible files'], $trashedLibrary, $s3rExplorer, $trashedZipsAndSearch, $p4cServed, $p5aPending],
    'admin.dam.hub.resources.list'              => [$hubResourceList],
    'admin.dam.model_thumbnails.missing'        => [$hiddenFolderEndpoints],
    'admin.dam.new-uploads.list'                => [['Feature/NewUserUploadsTest.php', 'refuses the New user uploads page and its actions to anyone but an admin']],
    'admin.dam.pages.index'                     => [['Feature/WebPageAuditTest.php', 'A1: a page in an ancestor-only viewable directory is neither listed nor openable']],
    'admin.dam.pages.listing'                   => [['Feature/WebPageIndexTest.php', 'scopes the listing to the directories the role can access']],
    // P1a: status-row counts and failure ids, filtered by the asset rule the Library lists by, in both access models.
    // P1b: every usage dashboard counts and names only what the viewer's Library lists, before aggregating.
    'admin.dam.reports.adoption'                => [$p1bReports],
    'admin.dam.reports.engagement'              => [$p1bReports],
    'admin.dam.reports.friction'                => [$p1bReports],
    'admin.dam.reports.journey'                 => [$p1bReports],
    'admin.dam.reports.journeys'                => [$p1bReports],
    'admin.dam.reports.operational-health'      => [['Feature/SecurityReportsTest.php', 'counts and lists only work on files the viewer can open']],
    'admin.dam.shared-links.active_for_target'  => [$s3Shares],
    'admin.dam.shared-links.index'              => [$s3Shares, $s3rShares],
    // Share pages in folder: the candidate list leaves out hidden pages and counts only what the viewer can share.
    'admin.dam.shared-links.web_pages_candidates' => [['Feature/WebCollectionTest.php', 'lists only the direct, visible web pages of a folder for sharing, and writes nothing']],
    // The Tags page grid: each tag's count counts only the files the viewer's Library lists.
    'admin.dam.tags.index'                      => [$s3rAggregates],
    // As admin.api.dam.tags.all: the list carries only tag ids and names, never an asset's id, name or count.
    'admin.dam.tags.list'                       => [$s3NoAssets],
    'admin.dam.trash.list'                      => [['Feature/TrashTest.php', 'lists the trashed assets the viewer could see, with who deleted each and when']],
    'admin.api.dam.trash.index'                 => [['Feature/Api/ReorganizeApiTest.php', 'lists only the trashed assets the key\'s admin could see']],
    'admin.dam.upload-requests.list'            => [$p5aRequests],
    'admin.dam.upload-requests.submissions'     => [$p5aSubmissions],
    'admin.home.calendar.assets.search'         => [$homeCalendar],
    'admin.home.calendar.events'                => [$homeCalendar],
    'admin.home.calendar.events.show'           => [$homeCalendar],
    'admin.home.plaque.sets'                    => [$homePlaque],
    'admin.home.data'                           => [$s3Home, $trashedLibrary],
    'admin.home.favorites.index'                => [$s3Home],
    'admin.home.stylepress.assets'              => [$s3Home],
    'admin.home.stylepress.folders'             => [$s3Home],
    'admin.home.summary'                        => [['Feature/HomeSummaryTest.php', 'counts only the files a restricted person may list']],
    'admin.home.video.jobs.file'                => [$homeVideo],
    'admin.home.vectorization.state'            => [$s3Home, $s3rDerived],
    // People and, under lists, folder roots; no asset. Probed that a member (every ACL key) is refused in both models:
    // only a full admin reads it (ManageFolderAccess).
    'admin.settings.groups.list'                => [$s3NoAssets],
    // P4: labels, boards, sections, saved searches and the REST Library listing.
    'admin.api.dam.boards.assets.index'       => [$p4Organization],
    'admin.api.dam.boards.index'              => [$p4Organization],
    'admin.api.dam.labels.assets.index'       => [$p4Organization],
    'admin.api.dam.labels.index'              => [$p4Organization],
    'admin.api.dam.library.assets'            => [$p4Organization],
    'admin.api.dam.saved-searches.assets'     => [$p4Organization],
    'admin.api.dam.sections.assets'           => [$p4Organization],
    'admin.api.dam.sections.index'            => [$p4Organization],
    'admin.dam.organize.index'                => [$p4Organization],
    // P5b: automation rules and metadata mappings: dry runs, run logs and the folder pickers.
    'admin.api.dam.automation_rules.dry_run'    => [$p5bRestRules],
    'admin.api.dam.automation_rules.index'      => [$p5bRestRules],
    'admin.api.dam.automation_rules.runs'       => [$p5bRestRules],
    'admin.api.dam.automation_rules.runs.show'  => [$p5bRestRules],
    'admin.api.dam.metadata_mappings.dry_run'   => [$p5bRestMappings],
    'admin.dam.configuration.mappings.dry_run'  => [$p5bWeb],
    'admin.dam.configuration.mappings.index'    => [$p5bWeb],
    'admin.dam.configuration.rules.dry_run'     => [$p5bWeb],
    'admin.dam.configuration.rules.index'       => [$p5bWeb],
    'admin.dam.configuration.rules.runs'        => [$p5bWeb],
    'admin.dam.configuration.rules.runs.show'   => [$p5bWeb],
    // P8′: templates.
    'admin.api.dam.templates.index'           => [$p8Rest],
    'admin.dam.templates.list'                => [$p8List],
    'admin.dam.templates.preview'             => [$p8Resources],
    'admin.dam.templates.resource'            => [$p8Resources],
    'admin.dam.templates.use.preview'         => [$p8Images],
    // Feedback & bug reports: a screenshot is readable by its reporter and triagers only.
    'admin.dam.feedback.screenshot'           => [['Feature/Feedback/FeedbackReportTest.php', 'hides another person\'s report and screenshot as if they did not exist']],
];
