<?php

return [
    [
        'key'   => 'api.dam',
        'name'  => 'dam::app.admin.dam.index.title',
        'route' => 'admin.api.dam.assets.index',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets',
        'name'  => 'dam::app.admin.acl.asset',
        'route' => 'admin.api.dam.assets.index',
        'sort'  => 1,
    ], [
        // P4a (D9): field definitions are readable by any key that may list assets. A second entry for the same key
        // must come before that key's children: Tree::add replaces the node, and with it any children added so far.
        'key'   => 'api.dam.assets',
        'name'  => 'dam::app.admin.acl.asset',
        'route' => 'admin.api.dam.metadata_fields.index',
        'sort'  => 1,
    ], [
        // The library tools for the local `mothership` launcher (LibraryToolController) reuse each REST twin's scope.
        'key'   => 'api.dam.assets',
        'name'  => 'dam::app.admin.acl.asset',
        'route' => 'admin.api.dam.library_tools.search',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.assets.edit',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.api.dam.assets.update',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.api.dam.library_tools.update',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.assets.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.upload',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.library_tools.save',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.chunk_upload.initialize',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.chunk_upload.status',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.chunk_upload.store',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.chunk_upload.complete',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.upload',
        'name'  => 'dam::app.admin.acl.upload',
        'route' => 'admin.api.dam.assets.chunk_upload.cancel',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.re-upload',
        'name'  => 'dam::app.admin.acl.re_upload',
        'route' => 'admin.api.dam.assets.reUpload',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.assets.download',
        'name'  => 'dam::app.admin.acl.download',
        'route' => 'admin.api.dam.assets.download',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.assets.download', 'name' => 'dam::app.admin.acl.download',
        'route' => 'admin.api.dam.assets.model-thumbnail.source', 'sort' => 5,
    ], [
        'key'   => 'api.dam.assets.download', 'name' => 'dam::app.admin.acl.download',
        'route' => 'admin.api.dam.assets.files.model-thumbnail.source', 'sort' => 5,
    ], [
        'key'   => 'api.dam.assets.download',
        'name'  => 'dam::app.admin.acl.download',
        'route' => 'admin.api.dam.library_tools.fetch',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.assets.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.assets.show',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.assets.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.library_tools.get_asset',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.assets.metadata',
        'name'  => 'dam::app.admin.acl.meta_data',
        'route' => 'admin.api.dam.assets.metadata',
        'sort'  => 7,
    ], [
        'key'   => 'api.dam.assets.relationships',
        'name'  => 'dam::app.admin.acl.relationships',
        'route' => 'admin.api.dam.assets.relationships.index',
        'sort'  => 8,
    ], [
        'key'   => 'api.dam.assets.relationships.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.assets.relationships.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.relationships.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.relationships.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.assets.relationships.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.assets.relationships.destroy',
        'sort'  => 3,
    ], [
        // P4 D14: related assets; every candidate is filtered by what the key's owner may list.
        'key'   => 'api.dam.assets.related',
        'name'  => 'dam::app.admin.acl.related',
        'route' => 'admin.api.dam.assets.related',
        'sort'  => 9,
    ], [
        'key'   => 'api.dam.assets.files',
        'name'  => 'dam::app.admin.acl.files',
        'route' => 'admin.api.dam.assets.files.index',
        'sort'  => 9,
    ], [
        'key'   => 'api.dam.assets.files.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.assets.files.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.files.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.library_tools.list_stack',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.files.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.files.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.assets.files.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.assets.files.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.files.promote',
        'name'  => 'dam::app.admin.acl.promote',
        'route' => 'admin.api.dam.assets.attachments.promote',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.assets.files.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.api.dam.assets.attachments.update',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.assets.files.reorder',
        'name'  => 'dam::app.admin.acl.reorder',
        'route' => 'admin.api.dam.assets.attachments.order',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.assets.files.history',
        'name'  => 'dam::app.admin.acl.history',
        'route' => 'admin.api.dam.assets.attachments.revisions',
        'sort'  => 7,
    ], [
        'key'   => 'api.dam.assets.files.restore',
        'name'  => 'dam::app.admin.acl.restore',
        'route' => 'admin.api.dam.assets.attachments.restore',
        'sort'  => 8,
    ], [
        'key'   => 'api.dam.assets.stack',
        'name'  => 'dam::app.admin.acl.stack',
        'route' => 'admin.api.dam.assets.stack.update',
        'sort'  => 10,
    ], [
        'key'   => 'api.dam.assets.merges',
        'name'  => 'dam::app.admin.acl.merges',
        'route' => 'admin.api.dam.assets.merges.index',
        'sort'  => 11,
    ], [
        'key'   => 'api.dam.assets.merges.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.assets.merges.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.merges.preview',
        'name'  => 'dam::app.admin.acl.merge-preview',
        'route' => 'admin.api.dam.assets.merges.preview',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.assets.merges.store',
        'name'  => 'dam::app.admin.acl.merge',
        'route' => 'admin.api.dam.assets.merges.store',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.assets.merges.split_preview',
        'name'  => 'dam::app.admin.acl.split-preview',
        'route' => 'admin.api.dam.assets.merges.split_preview',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.assets.merges.split',
        'name'  => 'dam::app.admin.acl.split',
        'route' => 'admin.api.dam.assets.merges.split',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.assets.merges.finalize',
        'name'  => 'dam::app.admin.acl.finalize',
        'route' => 'admin.api.dam.assets.merges.finalize',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.assets.merges.revoke_links',
        'name'  => 'dam::app.admin.acl.revoke-links',
        'route' => 'admin.api.dam.assets.merges.revoke_links',
        'sort'  => 7,
    ], [
        'key'   => 'api.dam.assets.stack_instead',
        'name'  => 'dam::app.admin.acl.stack-instead',
        'route' => 'admin.api.dam.assets.stack-instead',
        'sort'  => 12,
    ], [
        'key'   => 'api.dam.assets.stack_suggestions',
        'name'  => 'dam::app.admin.acl.stack-suggestions',
        'route' => 'admin.api.dam.stack-suggestions.index',
        'sort'  => 13,
    ], [
        // P5c (D16): near-duplicate pairs (the Library's list and one asset's) and "Not duplicates".
        'key'   => 'api.dam.similar',
        'name'  => 'dam::app.admin.acl.similar-images',
        'route' => 'admin.api.dam.similar.index',
        'sort'  => 14,
    ], [
        'key'   => 'api.dam.similar',
        'name'  => 'dam::app.admin.acl.similar-images',
        'route' => 'admin.api.dam.assets.similar',
        'sort'  => 14,
    ], [
        'key'   => 'api.dam.similar.dismiss',
        'name'  => 'dam::app.admin.acl.dismiss',
        'route' => 'admin.api.dam.similar.dismiss',
        'sort'  => 1,
    ], [
        // P5c (D8): the AI vocabulary and the AI tag review queue.
        'key'   => 'api.dam.ai_vocabulary',
        'name'  => 'dam::app.admin.acl.ai-vocabulary',
        'route' => 'admin.api.dam.ai-vocabulary.index',
        'sort'  => 15,
    ], [
        'key'   => 'api.dam.ai_vocabulary.manage',
        'name'  => 'dam::app.admin.acl.ai-vocabulary-manage',
        'route' => 'admin.api.dam.ai-vocabulary.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.ai_vocabulary.manage',
        'name'  => 'dam::app.admin.acl.ai-vocabulary-manage',
        'route' => 'admin.api.dam.ai-vocabulary.update',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.ai_review',
        'name'  => 'dam::app.admin.acl.ai-review',
        'route' => 'admin.api.dam.ai-review.index',
        'sort'  => 16,
    ], [
        'key'   => 'api.dam.ai_review.bulk',
        'name'  => 'dam::app.admin.acl.ai-review-bulk',
        'route' => 'admin.api.dam.ai-review.bulk',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.batches',
        'name'  => 'dam::app.admin.acl.batches',
        'route' => 'admin.api.dam.batches.show',
        'sort'  => 11,
    ], [
        'key'   => 'api.dam.batches.recipes',
        'name'  => 'dam::app.admin.acl.recipes',
        'route' => 'admin.api.dam.batches.recipes',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.batches.store',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.batches.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.batches.show',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.batches.show',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.batches.retry',
        'name'  => 'dam::app.admin.acl.retry',
        'route' => 'admin.api.dam.batches.items.retry',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.batches.cancel',
        'name'  => 'dam::app.admin.acl.cancel',
        'route' => 'admin.api.dam.batches.cancel',
        'sort'  => 5,
    ], [
        // Image Vectorization for agents: filed onto an asset or a one-off upload (docs/ops/image-vectorization.md).
        'key'   => 'api.dam.vectorizations',
        'name'  => 'dam::app.admin.acl.vectorizations',
        'route' => 'admin.api.dam.vectorizations.show',
        'sort'  => 12,
    ], [
        'key'   => 'api.dam.vectorizations.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.vectorizations.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.vectorizations.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.vectorize',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.vectorizations.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.library_tools.vectorize',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.vectorizations.show',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.vectorizations.show',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.vectorizations.show',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.vectorizations.file',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.vectorizations.show',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.library_tools.vectorization',
        'sort'  => 2,
    ], [
        // P6 D26: tokenized embeds of a video or audio asset.
        'key'   => 'api.dam.assets.embeds',
        'name'  => 'dam::app.admin.acl.embed',
        'route' => 'admin.api.dam.assets.embeds.index',
        'sort'  => 11,
    ], [
        'key'   => 'api.dam.assets.embeds.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.assets.embeds.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.assets.embeds.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.embeds.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.assets.embeds.revoke',
        'name'  => 'dam::app.admin.acl.revoke',
        'route' => 'admin.api.dam.assets.embeds.revoke',
        'sort'  => 3,
    ], [
        // P6 D26: re-queue a video's preview proxy; its status is the video_proxy field of the asset read.
        'key'   => 'api.dam.assets.video-proxy-retry',
        'name'  => 'dam::app.admin.acl.video-proxy-retry',
        'route' => 'admin.api.dam.assets.video-proxy.retry',
        'sort'  => 12,
    ], [
        // Move and copy run the Library's own actions, so the key's admin also needs the role permissions they ask for.
        'key'   => 'api.dam.assets.move',
        'name'  => 'dam::app.admin.acl.move',
        'route' => 'admin.api.dam.assets.move',
        'sort'  => 13,
    ], [
        'key'   => 'api.dam.assets.move',
        'name'  => 'dam::app.admin.acl.move',
        'route' => 'admin.api.dam.assets.mass_move',
        'sort'  => 13,
    ], [
        'key'   => 'api.dam.assets.copy',
        'name'  => 'dam::app.admin.acl.copy',
        'route' => 'admin.api.dam.assets.copy',
        'sort'  => 14,
    ], [
        'key'   => 'api.dam.directory',
        'name'  => 'dam::app.admin.acl.directory',
        'route' => 'admin.api.dam.directory.index',
        'sort'  => 1,
    ], [
        // Before api.dam.directory's children: Tree::add replaces the node, and with it any children added so far.
        'key'   => 'api.dam.directory',
        'name'  => 'dam::app.admin.acl.directory',
        'route' => 'admin.api.dam.library_tools.list_folders',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.directory.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.directory.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.directory.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.library_tools.create_folder',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.directory.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.directory.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.directory.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.directory.delete',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.directory.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.directory.get',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.directory.move',
        'name'  => 'dam::app.admin.acl.move',
        'route' => 'admin.api.dam.directory.move',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.directory.copy',
        'name'  => 'dam::app.admin.acl.copy',
        'route' => 'admin.api.dam.directory.copy',
        'sort'  => 6,
    ], [
        // The 30-day Trash. The parent key and `.get` share the list route, as for shares.
        'key'   => 'api.dam.trash',
        'name'  => 'dam::app.admin.acl.trash',
        'route' => 'admin.api.dam.trash.index',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.trash.get',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.trash.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.trash.restore',
        'name'  => 'dam::app.admin.acl.restore-from-trash',
        'route' => 'admin.api.dam.trash.restore',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.trash.purge',
        'name'  => 'dam::app.admin.acl.delete-permanently',
        'route' => 'admin.api.dam.trash.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.comment',
        'name'  => 'dam::app.admin.dam.index.directory.actions.comment',
        'route' => 'admin.api.dam.comment.get',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.comment.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.comment.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.comment.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.comment.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.comment.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.comment.delete',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.comment.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.comment.get',
        'sort'  => 4,
    ], [
        // P7 review: resolving needs comment edit (author or an api.dam.assets.update key, checked in the controller),
        // the review read needs comment read, and Watch and feedback requests need the right to comment.
        'key'   => 'api.dam.comment.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.comment.resolution',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.comment',
        'name'  => 'dam::app.admin.dam.index.directory.actions.comment',
        'route' => 'admin.api.dam.assets.review.show',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.comment.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.review.watch',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.comment.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.review.requests.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.comment.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.assets.review.requests.close',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.property',
        'name'  => 'dam::app.admin.acl.property',
        'route' => '',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.property.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.property.add',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.property.edit',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.property.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.property.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.property.get',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.property.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.property.delete',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.metadata-fields',
        'name'  => 'dam::app.admin.acl.metadata-fields',
        'route' => '',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.metadata-fields.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.metadata_fields.store',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.metadata-fields.update',
        'name'  => 'dam::app.admin.acl.update',
        'route' => 'admin.api.dam.metadata_fields.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.metadata-fields.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.metadata_fields.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.tags',
        'name'  => 'dam::app.admin.dam.index.datagrid.tags',
        'route' => 'admin.api.dam.tags.get',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.tags.all',
        'name'  => 'dam::app.admin.dam.index.datagrid.tags',
        'route' => 'admin.api.dam.tags.all',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.tags.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.tag.add',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.tags.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.tag.delete',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.tags.bulk-assign',
        'name'  => 'dam::app.admin.dam.tag.mass-action.assign-tags',
        'route' => 'admin.api.dam.tags.bulk_assign',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.tags.destroy',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.tags.destroy',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.linked-resource',
        'name'  => 'dam::app.admin.dam.index.directory.linked-resources',
        'route' => 'admin.api.dam.linked_resource.get',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.linked-resource.get',
        'name'  => 'dam::app.admin.dam.index.directory.linked-resources',
        'route' => 'admin.api.dam.linked_resource.get',
        'sort'  => 1,
    ], [
        // P4 D2: collections. One entry per route, as api.dam.shares.revoke covers reissue.
        'key'   => 'api.dam.collections',
        'name'  => 'dam::app.admin.acl.collections',
        'route' => 'admin.api.dam.collections.index',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.collections.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.collections.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.collections.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.collections.show',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.collections.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.collections.assets.index',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.collections.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.collections.store',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.update',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.assets.store',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.assets.order',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.assets.destroy',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.audience',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.collections.link.store',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.collections.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.collections.destroy',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.shares',
        'name'  => 'dam::app.admin.acl.shares',
        'route' => 'admin.api.dam.shares.index',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.shares.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.shares.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.shares.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.shares.show',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.shares.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.shares.store',
        'sort'  => 3,
    ], [
        // P4 D23: checking a custom slug is part of creating a link: the same key.
        'key'   => 'api.dam.shares.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.shares.slug_check',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.shares.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.shares.update',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.shares.revoke',
        'name'  => 'dam::app.admin.acl.revoke',
        'route' => 'admin.api.dam.shares.revoke',
        'sort'  => 5,
    ], [
        // Reissue (P2 S5a) is part of revoking: the same key, as api.catalog.attributes.edit covers several routes.
        'key'   => 'api.dam.shares.revoke',
        'name'  => 'dam::app.admin.acl.revoke',
        'route' => 'admin.api.dam.shares.reissue',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.shares.reauthorize',
        'name'  => 'dam::app.admin.dam.share.modal.reauthorize',
        'route' => 'admin.api.dam.shares.reauthorize',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.shares.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.shares.destroy',
        'sort'  => 7,
    ], [
        // P5a (D1): guest upload requests, staff only.
        'key'   => 'api.dam.upload_requests',
        'name'  => 'dam::app.admin.acl.upload-requests',
        'route' => 'admin.api.dam.upload_requests.index',
        'sort'  => 7,
    ], [
        'key'   => 'api.dam.upload_requests.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.upload_requests.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.upload_requests.getById',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get-by-id',
        'route' => 'admin.api.dam.upload_requests.show',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.upload_requests.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.upload_requests.store',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.upload_requests.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.upload_requests.update',
        'sort'  => 4,
    ], [
        // P5a (D1): Close and Reissue are edits of the request: the same key.
        'key'   => 'api.dam.upload_requests.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.upload_requests.close',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.upload_requests.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.upload_requests.reissue',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.upload_requests.submissions',
        'name'  => 'dam::app.admin.acl.upload-requests-submissions',
        'route' => 'admin.api.dam.upload_requests.submissions',
        'sort'  => 5,
    ], [
        // P5a (D1): Accept and Reject also need edit rights on the intake folder (IntakeReview).
        'key'   => 'api.dam.upload_requests.review',
        'name'  => 'dam::app.admin.acl.upload-requests-review',
        'route' => 'admin.api.dam.upload_requests.accept',
        'sort'  => 6,
    ], [
        'key'   => 'api.dam.upload_requests.review',
        'name'  => 'dam::app.admin.acl.upload-requests-review',
        'route' => 'admin.api.dam.upload_requests.reject',
        'sort'  => 6,
    ],
    // P4: Library organization over REST. The parent key and `.get` share the index route, as for shares.
    [
        'key'   => 'api.dam.library',
        'name'  => 'dam::app.admin.acl.library-listing',
        'route' => 'admin.api.dam.library.assets',
        'sort'  => 8,
    ], [
        'key'   => 'api.dam.library.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.library.assets',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.labels',
        'name'  => 'dam::app.admin.acl.labels',
        'route' => 'admin.api.dam.labels.index',
        'sort'  => 9,
    ], [
        'key'   => 'api.dam.labels.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.labels.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.labels.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.labels.assets.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.labels.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.labels.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.labels.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.labels.update',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.labels.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.labels.destroy',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.api.dam.labels.assets.store',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.api.dam.labels.assets.destroy',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.labels.assign',
        'name'  => 'dam::app.admin.acl.labels-assign',
        'route' => 'admin.api.dam.labels.assets.update',
        'sort'  => 5,
    ], [
        'key'   => 'api.dam.boards',
        'name'  => 'dam::app.admin.acl.boards',
        'route' => 'admin.api.dam.boards.index',
        'sort'  => 10,
    ], [
        'key'   => 'api.dam.boards.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.boards.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.boards.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.boards.assets.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.assets.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.assets.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.boards.manage',
        'name'  => 'dam::app.admin.acl.boards-manage',
        'route' => 'admin.api.dam.boards.assets.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.saved-searches',
        'name'  => 'dam::app.admin.acl.saved-searches',
        'route' => 'admin.api.dam.saved-searches.index',
        'sort'  => 11,
    ], [
        'key'   => 'api.dam.saved-searches.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.saved-searches.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.saved-searches.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.saved-searches.assets',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.saved-searches.create',
        'name'  => 'dam::app.admin.acl.create',
        'route' => 'admin.api.dam.saved-searches.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.saved-searches.update',
        'name'  => 'dam::app.admin.acl.edit',
        'route' => 'admin.api.dam.saved-searches.update',
        'sort'  => 3,
    ], [
        'key'   => 'api.dam.saved-searches.delete',
        'name'  => 'dam::app.admin.acl.delete',
        'route' => 'admin.api.dam.saved-searches.destroy',
        'sort'  => 4,
    ], [
        'key'   => 'api.dam.sections',
        'name'  => 'dam::app.admin.acl.sections',
        'route' => 'admin.api.dam.sections.index',
        'sort'  => 12,
    ], [
        'key'   => 'api.dam.sections.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.sections.index',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.sections.get',
        'name'  => 'dam::app.admin.dam.index.directory.actions.get',
        'route' => 'admin.api.dam.sections.assets',
        'sort'  => 1,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.update',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.pins.store',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.pins.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'api.dam.sections.manage',
        'name'  => 'dam::app.admin.acl.sections-manage',
        'route' => 'admin.api.dam.sections.pins.update',
        'sort'  => 2,
    ],
    // P5b (D18, D19): metadata mappings and automation rules.
    [
        'key'   => 'api.dam.mapping',
        'name'  => 'dam::app.admin.acl.mappings',
        'route' => 'admin.api.dam.metadata_mappings.index',
        'sort'  => 12,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.show',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.destroy',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.dry_run',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.mapping.manage',
        'name'  => 'dam::app.admin.acl.mappings-manage',
        'route' => 'admin.api.dam.metadata_mappings.run',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation',
        'name'  => 'dam::app.admin.acl.automation',
        'route' => 'admin.api.dam.automation_rules.index',
        'sort'  => 13,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.store',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.show',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.update',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.destroy',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.dry_run',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.run',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.runs',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.automation.manage',
        'name'  => 'dam::app.admin.acl.automation-manage',
        'route' => 'admin.api.dam.automation_rules.runs.show',
        'sort'  => 1,
    ],
    // P8′ (D25): templates over REST; the key's admin must also hold the matching dam.template.* role permission.
    [
        'key'   => 'api.dam.templates',
        'name'  => 'dam::app.admin.acl.templates',
        'route' => 'admin.api.dam.templates.index',
        'sort'  => 12,
    ],
    [
        'key'   => 'api.dam.templates.index',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.templates.index',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.templates.show',
        'name'  => 'dam::app.admin.acl.view',
        'route' => 'admin.api.dam.templates.show',
        'sort'  => 1,
    ],
    [
        'key'   => 'api.dam.templates.use',
        'name'  => 'dam::app.admin.acl.templates-use',
        'route' => 'admin.api.dam.templates.assets.store',
        'sort'  => 2,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.store',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.update',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.destroy',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.publish',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.archive',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.templates.manage',
        'name'  => 'dam::app.admin.acl.templates-manage',
        'route' => 'admin.api.dam.templates.images.store',
        'sort'  => 3,
    ],
    [
        'key'   => 'api.dam.assets.model-thumbnails', 'name' => 'dam::app.admin.acl.model-thumbnails',
        'route' => 'admin.api.dam.assets.model-thumbnail.show', 'sort' => 20,
    ],
    ...array_map(fn ($route) => [
        'key'   => 'api.dam.assets.model-thumbnails.read', 'name' => 'dam::app.admin.acl.view',
        'route' => $route, 'sort' => 1,
    ], [
        'admin.api.dam.assets.model-thumbnail.show', 'admin.api.dam.assets.model-thumbnail.image',
        'admin.api.dam.assets.files.model-thumbnail.show', 'admin.api.dam.assets.files.model-thumbnail.image',
    ]),
    ...array_map(fn ($route) => [
        'key'   => 'api.dam.assets.model-thumbnails.write', 'name' => 'dam::app.admin.acl.edit',
        'route' => $route, 'sort' => 2,
    ], ['admin.api.dam.assets.model-thumbnail.store', 'admin.api.dam.assets.files.model-thumbnail.store']),
];
