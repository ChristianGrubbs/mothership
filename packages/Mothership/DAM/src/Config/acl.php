<?php

return [
    [
        'key'   => 'dam',
        'name'  => 'dam::app.admin.acl.menu',
        'route' => 'admin.dam.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset',
        'name'  => 'dam::app.admin.acl.asset',
        'route' => 'admin.dam.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.assets.edit',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.show',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.info',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.open',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.pages.grant',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.pages.show',
        'sort'  => 2,
    ], [
        // The stack members of a web page, read-only: the preview's file selector. The WebPages-only role holds
        // dam.asset.view, so it needs no relationship-editing or asset-files listing permission.
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.pages.members',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.file.asset-file',
        'sort'  => 2,
    ], [
        // P4 D14: the asset page's Related section; the ranking filters every candidate through the access seam.
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.related',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.assets.update',
        'sort'  => 3,
    ], [
        // P4 D8: adding and removing tags on an asset (one, in bulk, or by AI review), apart from dam.asset.update.
        // Migration 2026_10_07_001003 gave it to every role that held dam.asset.update.
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.tag',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.remove-tag',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.mass_assign_tags',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.ai-tags.accept',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.ai-tags.dismiss',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.ai-tags.accept-all',
        'sort'  => 3,
    ], [
        // Asking the AI for a description and suggestions is part of reviewing them.
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.ai-tags.describe',
        'sort'  => 3,
    ], [
        // One stacked file's own description: asking for it is tagging, as for the cover.
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.assets.ai-tags.member.describe',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.ai-tags.member',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.ai-tags.show',
        'sort'  => 2,
    ], [
        // Which of the selected files the AI can describe: read-only, so viewing is enough.
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.ai-tags.eligibility',
        'sort'  => 2,
    ], [
        // P5c (D8): the AI tag review page reviews the same suggestions in bulk.
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.ai-review.index',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.tag',
        'name'  => 'dam::app.admin.acl.tag-assets',
        'route' => 'admin.dam.ai-review.bulk',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.dam.assets.upload',
        'sort'  => 4,
    ], [
        'key'   => 'dam.asset.re_upload',
        'name'  => 'dam::app.admin.acl.re_upload',
        'route' => 'admin.dam.assets.re_upload',
        'sort'  => 5,
    ], [
        'key'   => 'dam.asset.editor',
        'name'  => 'Image editor',
        'route' => 'admin.dam.assets.editor.document',
        'sort'  => 5,
    ], [
        'key'   => 'dam.asset.editor',
        'name'  => 'Image editor',
        'route' => 'admin.dam.assets.editor.save',
        'sort'  => 5,
    ], [
        'key'   => 'dam.asset.editor',
        'name'  => 'Image editor',
        'route' => 'admin.dam.assets.editor.upload',
        'sort'  => 5,
    ], [
        'key'   => 'dam.asset.editor',
        'name'  => 'Image editor',
        'route' => 'admin.dam.assets.editor.resource',
        'sort'  => 5,
    ], [
        'key'   => 'dam.asset.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.assets.destroy',
        'sort'  => 6,
    ], [
        'key'   => 'dam.asset.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.pages.destroy',
        'sort'  => 6,
    ], [
        'key'   => 'dam.asset.mass_delete',
        'name'  => 'dam::app.admin.acl.mass_delete',
        'route' => 'admin.dam.assets.mass_delete',
        'sort'  => 7,
    ], [
        'key'   => 'dam.asset.download',
        'name'  => 'dam::app.admin.acl.download',
        'route' => 'admin.dam.assets.download',
        'sort'  => 8,
    ], [
        'key'   => 'dam.asset.download_compressed',
        'name'  => 'dam::app.admin.acl.download-zip',
        'route' => 'admin.dam.assets.download_compressed',
        'sort'  => 9,
    ], [
        'key'   => 'dam.asset.rename',
        'name'  => 'dam::app.admin.acl.rename',
        'route' => 'admin.dam.assets.rename',
        'sort'  => 9,
    ], [
        'key'   => 'dam.asset.rename',
        'name'  => 'dam::app.admin.acl.rename',
        'route' => 'admin.dam.assets.ai-rename.suggest',
        'sort'  => 9,
    ], [
        'key'   => 'dam.asset.moved',
        'name'  => 'dam::app.admin.acl.move',
        'route' => 'admin.dam.assets.moved',
        'sort'  => 10,
    ], [
        'key'   => 'dam.asset.share',
        'name'  => 'dam::app.admin.acl.share',
        'route' => 'admin.dam.shared-links.store',
        // dam.directory.share registers the same route later and wins the route→key map, so an
        // asset-only sharer was refused at the middleware (audit A7). The controller still checks
        // the permission that matches share_type.
        'also_authorizes' => ['admin.dam.shared-links.store'],
        'sort'            => 11,
    ], [
        // Share pages in folder: the read-only candidate list takes the page-link permission, as creating one does.
        'key'   => 'dam.asset.share',
        'name'  => 'dam::app.admin.acl.share',
        'route' => 'admin.dam.shared-links.web_pages_candidates',
        'sort'  => 11,
    ], [
        // P6 D26: tokenized embeds of a video or audio asset; one permission lists, makes and revokes them.
        'key'   => 'dam.asset.embed',
        'name'  => 'dam::app.admin.acl.embed',
        'route' => 'admin.dam.assets.embeds.store',
        'sort'  => 11,
    ], [
        'key'   => 'dam.asset.embed',
        'name'  => 'dam::app.admin.acl.embed',
        'route' => 'admin.dam.assets.embeds.index',
        'sort'  => 11,
    ], [
        'key'   => 'dam.asset.embed',
        'name'  => 'dam::app.admin.acl.embed',
        'route' => 'admin.dam.assets.embeds.revoke',
        'sort'  => 11,
    ], [
        // P6 D26: the preview proxy's status reads with the asset; Retry changes it, so it takes edit.
        'key'   => 'dam.asset.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.video-proxy.state',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.assets.video-proxy.retry',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.relationships',
        'name'  => 'dam::app.admin.acl.relationships',
        'route' => 'admin.dam.assets.relationships.index',
        'sort'  => 12,
    ], [
        'key'   => 'dam.asset.relationships.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.relationships.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.relationships.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.assets.relationships.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.relationships.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.assets.relationships.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.files',
        'name'  => 'dam::app.admin.acl.files',
        'route' => 'admin.dam.assets.files.index',
        'sort'  => 13,
    ], [
        'key'   => 'dam.asset.files.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.assets.files.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.asset.files.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.assets.files.store',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.asset.files.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.assets.files.destroy',
        'sort'  => 3,
    ],

    // P6a: running recipes over assets (Process dialog, Export menu) and reading, retrying or cancelling one's batches.
    [
        'key'   => 'dam.asset.process',
        'name'  => 'dam::app.admin.acl.process',
        'route' => 'admin.dam.batches.store',
        'sort'  => 14,
    ], [
        'key'   => 'dam.asset.process',
        'name'  => 'dam::app.admin.acl.process',
        'route' => 'admin.dam.batches.recipes',
        'sort'  => 14,
    ], [
        'key'   => 'dam.asset.process',
        'name'  => 'dam::app.admin.acl.process',
        'route' => 'admin.dam.batches.show',
        'sort'  => 14,
    ], [
        'key'   => 'dam.asset.process',
        'name'  => 'dam::app.admin.acl.process',
        'route' => 'admin.dam.batches.items.retry',
        'sort'  => 14,
    ], [
        'key'   => 'dam.asset.process',
        'name'  => 'dam::app.admin.acl.process',
        'route' => 'admin.dam.batches.cancel',
        'sort'  => 14,
    ],

    [
        'key'   => 'dam.asset.property',
        'name'  => 'dam::app.admin.acl.property',
        'route' => 'admin.dam.asset.properties.index',
        'sort'  => 13,
    ], [
        'key'   => 'dam.asset.property.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.asset.properties.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.property.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.asset.property.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.property.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.asset.properties.update',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.property.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.asset.properties.delete',
        'sort'  => 4,
    ], [
        'key'   => 'dam.asset.comment',
        'name'  => 'dam::app.admin.acl.comment',
        'route' => 'admin.dam.asset.comments.index',
        'sort'  => 14,
    ], [
        'key'   => 'dam.asset.comment.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.asset.comments.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.comment.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.asset.comment.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.comment.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.asset.comment.update',
        'sort'  => 3,
    ], [
        'key'   => 'dam.asset.comment.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.asset.comment.delete',
        'sort'  => 4,
    ], [
        // P7 review: reading rounds, Watch and resolving need the comment view right (the author-or-editor rule for
        // resolving is in CommentController); the people picker and feedback requests need the right to comment.
        'key'   => 'dam.asset.comment.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.asset.review.show',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.comment.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.asset.review.watch',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.comment.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.asset.comment.resolution',
        'sort'  => 1,
    ], [
        'key'   => 'dam.asset.comment.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.asset.review.people',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.comment.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.asset.review.requests.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.comment.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.asset.review.requests.close',
        'sort'  => 2,
    ], [
        'key'   => 'dam.asset.meta_data',
        'name'  => 'dam::app.admin.acl.meta_data',
        'route' => 'admin.dam.assets.metadata',
        'sort'  => 16,
    ], [
        'key'   => 'dam.asset.linked_resources',
        'name'  => 'dam::app.admin.acl.linked_resources',
        'route' => 'admin.dam.asset.linked_resources.index',
        'sort'  => 15,
    ], [
        'key'   => 'dam.asset.linked_resources.index',
        'name'  => 'dam::app.admin.acl.linked_resources',
        'route' => 'admin.dam.asset.linked_resources.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.directory',
        'name'  => 'dam::app.admin.acl.directory',
        'route' => 'admin.dam.directory.index',
        'sort'  => 2,
    ], [
        'key'   => 'dam.directory.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.directory.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.directory.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.directory.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.directory.rename',
        'name'  => 'dam::app.admin.acl.rename',
        'route' => 'admin.dam.directory.update',
        'sort'  => 3,
    ], [
        'key'   => 'dam.directory.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.directory.destroy',
        'sort'  => 4,
    ], [
        'key'   => 'dam.directory.copy_structure',
        'name'  => 'dam::app.admin.acl.copy-structure',
        'route' => 'admin.dam.directory.copy_structure',
        'sort'  => 5,
    ], [
        'key'   => 'dam.directory.download_zip',
        'name'  => 'dam::app.admin.acl.download-zip',
        'route' => 'admin.dam.directory.zip_download',
        'sort'  => 5,
    ], [
        'key'   => 'dam.directory.moved',
        'name'  => 'dam::app.admin.acl.move',
        'route' => 'admin.dam.directory.moved',
        'sort'  => 6,
    ], [
        'key'   => 'dam.directory.share',
        'name'  => 'dam::app.admin.acl.share',
        'route' => 'admin.dam.shared-links.store',
        'sort'  => 7,
    ], [
        'key'   => 'dam.asset_assign',
        'name'  => 'dam::app.admin.acl.asset-assign',
        'route' => 'admin.dam.asset_picker.get_assets',
        'sort'  => 3,
    ], [
        'key'   => 'dam.assets',
        'name'  => 'dam::app.admin.acl.assets',
        'route' => 'admin.dam.assets.index',
        'sort'  => 4,
    ], [
        'key'   => 'dam.shares',
        'name'  => 'dam::app.admin.acl.shares',
        'route' => 'admin.dam.shared-links.index',
        'sort'  => 5,
    ], [
        'key'   => 'dam.shares.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.shared-links.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.shares.revoke',
        'name'  => 'dam::app.admin.acl.revoke',
        'route' => 'admin.dam.shared-links.revoke',
        'sort'  => 2,
    ], [
        'key'   => 'dam.shares.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.shared-links.destroy',
        'sort'  => 3,
    ], [
        // P4 D2: collections. Reads (the page, lists, member files) are open to every signed-in user and decided per
        // collection; changes need these keys and managing the collection. One entry per route (Tree::add).
        'key'   => 'dam.collections',
        'name'  => 'dam::app.admin.acl.collections',
        'route' => 'admin.dam.collections.store',
        'sort'  => 5,
    ], [
        'key'   => 'dam.collections.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.collections.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.update',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.audience',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.members.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.members.order',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.members.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.collections.link.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.collections.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.collections.destroy',
        'sort'  => 3,
    ], [
        // P2 S4: the 30-day trash. One entry per route (Tree::add keys roles by a single route string).
        'key'   => 'dam.trash',
        'name'  => 'dam::app.admin.acl.trash',
        'route' => 'admin.dam.trash.index',
        'sort'  => 5,
    ], [
        'key'   => 'dam.trash.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.trash.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.trash.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.trash.list',
        'sort'  => 1,
    ], [
        'key'   => 'dam.trash.restore',
        'name'  => 'dam::app.admin.acl.restore-from-trash',
        'route' => 'admin.dam.trash.restore',
        'sort'  => 2,
    ], [
        'key'   => 'dam.trash.delete',
        'name'  => 'dam::app.admin.acl.delete-permanently',
        'route' => 'admin.dam.trash.destroy',
        'sort'  => 3,
    ], [
        // P5a (D1): guest upload requests. One entry per route. Not in RoleBuilder::CAPABILITIES: a custom role gets
        // these only when someone ticks them. Accept/Reject also need edit rights on the intake folder (IntakeReview).
        'key'   => 'dam.upload_requests',
        'name'  => 'dam::app.admin.acl.upload-requests',
        'route' => 'admin.dam.upload-requests.index',
        'sort'  => 6,
    ], [
        'key'   => 'dam.upload_requests.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.upload-requests.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.upload_requests.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.upload-requests.list',
        'sort'  => 1,
    ], [
        'key'   => 'dam.upload_requests.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.upload-requests.show',
        'sort'  => 1,
    ], [
        'key'   => 'dam.upload_requests.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.upload-requests.submissions',
        'sort'  => 1,
    ], [
        'key'   => 'dam.upload_requests.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.upload-requests.store',
        'sort'  => 2,
    ], [
        'key'             => 'dam.upload_requests.edit',
        'name'            => 'dam::app.admin.acl.edit',
        'route'           => 'admin.dam.upload-requests.update',
        'sort'            => 3,
    ], [
        'key'   => 'dam.upload_requests.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.upload-requests.close',
        'sort'  => 3,
    ], [
        'key'   => 'dam.upload_requests.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.upload-requests.reissue',
        'sort'  => 3,
    ], [
        'key'   => 'dam.upload_requests.review',
        'name'  => 'dam::app.admin.acl.upload-requests-review',
        'route' => 'admin.dam.upload-requests.accept',
        'sort'  => 4,
    ], [
        'key'   => 'dam.upload_requests.review',
        'name'  => 'dam::app.admin.acl.upload-requests-review',
        'route' => 'admin.dam.upload-requests.reject',
        'sort'  => 4,
    ], [
        // Staff-only reports (P1a). Deliberately absent from RoleBuilder::CAPABILITIES, so "Use Sandbox
        // defaults" never ticks it; a custom role gets it only when someone ticks it under Advanced permissions.
        'key'   => 'dam.reports',
        'name'  => 'dam::app.admin.acl.reports',
        'sort'  => 7,
    ], [
        'key'   => 'dam.reports.security',
        'name'  => 'dam::app.admin.acl.security-reports',
        'route' => 'admin.dam.reports.login-access',
        'sort'  => 1,
    ], [
        'key'   => 'dam.reports.security',
        'name'  => 'dam::app.admin.acl.security-reports',
        'route' => 'admin.dam.reports.operational-health',
        'sort'  => 1,
    ], [
        // Product analytics (P1b, §3.6.8). Like the security reports, never a Sandbox default.
        'key'   => 'dam.reports.view',
        'name'  => 'dam::app.admin.acl.analytics-reports',
        'route' => 'admin.dam.reports.engagement',
        'sort'  => 2,
    ], [
        'key'   => 'dam.reports.view',
        'name'  => 'dam::app.admin.acl.analytics-reports',
        'route' => 'admin.dam.reports.friction',
        'sort'  => 2,
    ], [
        'key'   => 'dam.reports.view',
        'name'  => 'dam::app.admin.acl.analytics-reports',
        'route' => 'admin.dam.reports.adoption',
        'sort'  => 2,
    ], [
        'key'   => 'dam.reports.journeys',
        'name'  => 'dam::app.admin.acl.analytics-journeys',
        'route' => 'admin.dam.reports.journeys',
        'sort'  => 3,
    ], [
        'key'   => 'dam.reports.journeys',
        'name'  => 'dam::app.admin.acl.analytics-journeys',
        'route' => 'admin.dam.reports.journey',
        'sort'  => 3,
    ], [
        // No route: decides whether a session timeline names the person or shows a pseudonym.
        'key'   => 'dam.reports.identify',
        'name'  => 'dam::app.admin.acl.analytics-identify',
        'sort'  => 4,
    ], [
        'key'   => 'dam.pages',
        'name'  => 'dam::app.admin.acl.pages',
        'route' => 'admin.dam.pages.index',
        'sort'  => 6,
    ], [
        'key'   => 'dam.pages',
        'name'  => 'dam::app.admin.acl.pages',
        'route' => 'admin.dam.pages.listing',
        'sort'  => 6,
    ], [
        // ADR 0008: one entry per route (Tree::add keys roles by a single route string).
        'key'   => 'dam.pages.organize',
        'name'  => 'dam::app.admin.acl.pages-organize',
        'route' => 'admin.dam.pages.collections.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.pages.organize',
        'name'  => 'dam::app.admin.acl.pages-organize',
        'route' => 'admin.dam.pages.collections.update',
        'sort'  => 1,
    ], [
        'key'   => 'dam.pages.organize',
        'name'  => 'dam::app.admin.acl.pages-organize',
        'route' => 'admin.dam.pages.collections.destroy',
        'sort'  => 1,
    ], [
        'key'   => 'dam.pages.organize',
        'name'  => 'dam::app.admin.acl.pages-organize',
        'route' => 'admin.dam.pages.move',
        'sort'  => 1,
    ], [
        'key'   => 'dam.tags',
        'name'  => 'dam::app.admin.acl.tags',
        'route' => 'admin.dam.tags.index',
        'sort'  => 6,
    ], [
        'key'   => 'dam.tags.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.tags.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.tags.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.tags.update',
        'sort'  => 2,
    ], [
        'key'   => 'dam.tags.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.tags.destroy',
        'sort'  => 3,
    ], [
        // P4a (D9): custom metadata field definitions. One entry per route (Tree::add keys roles by a single route).
        'key'   => 'dam.metadata_fields',
        'name'  => 'dam::app.admin.acl.metadata-fields',
        'route' => 'admin.dam.configuration.fields.index',
        'sort'  => 7,
    ], [
        'key'   => 'dam.metadata_fields.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.dam.configuration.fields.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.metadata_fields.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.configuration.fields.update',
        'sort'  => 2,
    ], [
        'key'   => 'dam.metadata_fields.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.configuration.fields.order',
        'sort'  => 2,
    ], [
        'key'   => 'dam.metadata_fields.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.dam.configuration.fields.restore',
        'sort'  => 2,
    ], [
        'key'   => 'dam.metadata_fields.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.dam.configuration.fields.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'dam.configuration',
        'name'  => 'dam::app.admin.acl.configuration',
        'route' => 'admin.dam.configuration.index',
        'sort'  => 6,
    ], [
        'key'   => 'dam.configuration.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.configuration.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.configuration.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.dam.configuration.update',
        'sort'  => 2,
    ],
    // P5c (D16): the Similar images page. Migration 2026_10_07_001703 gave the key to every role holding dam.asset.view.
    [
        'key'   => 'dam.duplicates',
        'name'  => 'dam::app.admin.acl.duplicates',
        'route' => null,
        'sort'  => 9,
    ], [
        'key'   => 'dam.duplicates.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.similar.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.duplicates.view',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.dam.similar.dismiss',
        'sort'  => 1,
    ],
    // P5c (D8): the AI vocabulary screen. Staff only: no migration grants it, and a full admin holds it.
    [
        'key'   => 'dam.ai',
        'name'  => 'dam::app.admin.acl.ai',
        'route' => null,
        'sort'  => 10,
    ], [
        'key'   => 'dam.ai.vocabulary',
        'name'  => 'dam::app.admin.acl.ai-vocabulary',
        'route' => null,
        'sort'  => 1,
    ], [
        'key'   => 'dam.ai.vocabulary.manage',
        'name'  => 'dam::app.admin.acl.ai-vocabulary-manage',
        'route' => 'admin.dam.configuration.ai-vocabulary.index',
        'sort'  => 1,
    ], [
        'key'   => 'dam.ai.vocabulary.manage',
        'name'  => 'dam::app.admin.acl.ai-vocabulary-manage',
        'route' => 'admin.dam.configuration.ai-vocabulary.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.ai.vocabulary.manage',
        'name'  => 'dam::app.admin.acl.ai-vocabulary-manage',
        'route' => 'admin.dam.configuration.ai-vocabulary.update',
        'sort'  => 1,
    ],
    // P4: Library organization. Browsing labels, sections, boards and saved searches needs only dam.asset.view
    // (checked in the controllers); boards and saved searches are personal, so they carry no role permission.
    [
        'key'   => 'dam.labels',
        'name'  => 'dam::app.admin.acl.labels',
        'route' => null,
        'sort'  => 7,
    ], [
        'key'   => 'dam.labels.manage',
        'name'  => 'dam::app.admin.acl.labels-manage',
        'route' => 'admin.dam.labels.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.labels.manage',
        'name'  => 'dam::app.admin.acl.labels-manage',
        'route' => 'admin.dam.labels.update',
        'sort'  => 1,
    ], [
        'key'   => 'dam.labels.manage',
        'name'  => 'dam::app.admin.acl.labels-manage',
        'route' => 'admin.dam.labels.destroy',
        'sort'  => 1,
    ], [
        'key'   => 'dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.dam.labels.assets.store',
        'sort'  => 2,
    ], [
        'key'   => 'dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.dam.labels.assets.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.dam.labels.assets.update',
        'sort'  => 2,
    ], [
        'key'   => 'dam.sections',
        'name'  => 'dam::app.admin.acl.sections',
        'route' => null,
        'sort'  => 8,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.update',
        'sort'  => 1,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.destroy',
        'sort'  => 1,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.pins.store',
        'sort'  => 1,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.pins.destroy',
        'sort'  => 1,
    ], [
        'key'   => 'dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.dam.sections.pins.update',
        'sort'  => 1,
    ],
    // P5b (D18, D19): metadata mappings and automation rules. One entry per route (Tree::add keys roles by a single route).
    [
        'key'   => 'dam.mapping',
        'name'  => 'dam::app.admin.acl.mappings',
        'route' => null,
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.keys',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.dry_run',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.destroy',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.dam.configuration.mappings.run',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation',
        'name'  => 'dam::app.admin.acl.automation',
        'route' => null,
        'sort'  => 10,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.dry_run',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.destroy',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.run',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.runs',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.dam.configuration.rules.runs.show',
        'sort'  => 1,
    ],
    // P8′ (D25): template-based asset creation. dam.template.use fills a published template into a new asset (the
    // filler also needs dam.asset.upload and access to the destination folder); dam.template.manage authors and
    // publishes templates. The list, a template and its images are open to either.
    [
        'key'   => 'dam.template',
        'name'  => 'dam::app.admin.acl.templates',
        'route' => null,
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.list',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.show',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.resource',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.use',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.use.preview',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.dam.templates.assets.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.store',
        'sort'  => 2,
        // A manager reads the list, a template and its images without dam.template.use.
        'also_authorizes' => ['admin.dam.templates.index', 'admin.dam.templates.list', 'admin.dam.templates.show', 'admin.dam.templates.resource'],
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.edit',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.update',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.destroy',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.publish',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.archive',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.images.store',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.layout',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.layout.save',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.template.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.dam.templates.preview',
        'sort'  => 2,
    ],
    [
        'key'   => 'dam.transfers',
        'name'  => 'dam::transfers.title',
        'route' => 'admin.dam.transfers.index',
        'sort'  => 8,
    ],
    [
        'key'   => 'dam.transfers',
        'name'  => 'dam::transfers.title',
        'route' => 'admin.dam.transfers.grant',
        'sort'  => 8,
    ],
    [
        // Routeless since the in-app Creative Hub went (2026-11-17): the Craft MCP server and Craft drop links check it,
        // and it is the parent of dam.hub.resources. Every non-API user holds it (Admin::hasPermission).
        'key'   => 'dam.hub',
        'name'  => 'dam::app.admin.acl.hub',
        'sort'  => 7,
    ],
    [
        // Hub resources (ADR 0015): the fonts and brushes people upload for the hub apps. Every non-API user holds it
        // (Admin::hasPermission), like dam.hub; who may read, change or delete one is decided per resource.
        'key'   => 'dam.hub.resources',
        'name'  => 'dam::app.admin.hub-resources.acl.resources',
        'route' => 'admin.dam.hub.resources.list',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.hub.resources',
        'name'  => 'dam::app.admin.hub-resources.acl.resources',
        'route' => 'admin.dam.hub.resources.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.hub.resources',
        'name'  => 'dam::app.admin.hub-resources.acl.resources',
        'route' => 'admin.dam.hub.resources.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.hub.resources',
        'name'  => 'dam::app.admin.hub-resources.acl.resources',
        'route' => 'admin.dam.hub.resources.destroy',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.hub.resources',
        'name'  => 'dam::app.admin.hub-resources.acl.resources',
        'route' => 'admin.dam.hub.resources.bytes',
        'sort'  => 1,
    ],
    [
        // Routeless: HubResourceController checks it when a resource is shared with Everyone or groups, which also
        // needs a license attestation. Held by every non-API user, like dam.hub.
        'key'   => 'dam.hub.resources.share',
        'name'  => 'dam::app.admin.hub-resources.acl.share',
        'sort'  => 1,
    ],
    [
        // Feedback & bug reports. Every non-API user holds dam.feedback
        // (Admin::hasPermission), like dam.hub: they send reports and read their own. FeedbackAccess decides per report.
        'key'   => 'dam.feedback',
        'name'  => 'dam::app.admin.feedback.acl.submit',
        'route' => 'admin.dam.feedback.store',
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.feedback',
        'name'  => 'dam::app.admin.feedback.acl.submit',
        'route' => 'admin.dam.feedback.mine',
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.feedback',
        'name'  => 'dam::app.admin.feedback.acl.submit',
        'route' => 'admin.dam.feedback.show',
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.feedback',
        'name'  => 'dam::app.admin.feedback.acl.submit',
        'route' => 'admin.dam.feedback.screenshot',
        'sort'  => 9,
    ],
    [
        'key'   => 'dam.feedback.manage',
        'name'  => 'dam::app.admin.feedback.acl.manage',
        'route' => 'admin.dam.feedback.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.feedback.manage',
        'name'  => 'dam::app.admin.feedback.acl.manage',
        'route' => 'admin.dam.feedback.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'dam.feedback.manage',
        'name'  => 'dam::app.admin.feedback.acl.manage',
        'route' => 'admin.dam.feedback.notes.store',
        'sort'  => 1,
    ],
];
