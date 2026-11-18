<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\API\Asset\AiTagReviewController;
use Mothership\DAM\Http\Controllers\API\Asset\AiVocabularyController;
use Mothership\DAM\Http\Controllers\API\Asset\AssetController;
use Mothership\DAM\Http\Controllers\API\Asset\AssetEmbedController;
use Mothership\DAM\Http\Controllers\API\Asset\AssetRelationshipController;
use Mothership\DAM\Http\Controllers\API\Asset\AttachmentController;
use Mothership\DAM\Http\Controllers\API\Asset\AutomationRuleController;
use Mothership\DAM\Http\Controllers\API\Asset\CollectionController;
use Mothership\DAM\Http\Controllers\API\Asset\CommentController;
use Mothership\DAM\Http\Controllers\API\Asset\DirectoryController;
use Mothership\DAM\Http\Controllers\API\Asset\DuplicateController;
use Mothership\DAM\Http\Controllers\API\Asset\LinkedResourcesController;
use Mothership\DAM\Http\Controllers\API\Asset\MergeController;
use Mothership\DAM\Http\Controllers\API\Asset\MetadataFieldController;
use Mothership\DAM\Http\Controllers\API\Asset\MetadataMappingController;
use Mothership\DAM\Http\Controllers\API\Asset\ModelThumbnailController;
use Mothership\DAM\Http\Controllers\API\Asset\ProcessingBatchController;
use Mothership\DAM\Http\Controllers\API\Asset\PropertyController;
use Mothership\DAM\Http\Controllers\API\Asset\RelatedAssetController;
use Mothership\DAM\Http\Controllers\API\Asset\ReorganizeController;
use Mothership\DAM\Http\Controllers\API\Asset\ReviewController;
use Mothership\DAM\Http\Controllers\API\Asset\ShareController;
use Mothership\DAM\Http\Controllers\API\Asset\SimilarImageController;
use Mothership\DAM\Http\Controllers\API\Asset\TagController;
use Mothership\DAM\Http\Controllers\API\Asset\TemplateController;
use Mothership\DAM\Http\Controllers\API\Asset\UploadRequestController;
use Mothership\DAM\Http\Controllers\API\Asset\VideoProxyController;

Route::group([
    'middleware' => [
        'auth:api',
    ],
], function () {
    // The same capture contract for the representative and an explicitly identified compound file.
    foreach (['assets/{asset}/model-thumbnail' => '', 'assets/{asset}/files/{file}/model-thumbnail' => 'files.'] as $path => $name) {
        Route::controller(ModelThumbnailController::class)->prefix($path)->group(function () use ($name) {
            foreach (['show' => '', 'source' => '/source', 'image' => '/image'] as $action => $suffix) {
                Route::get($suffix, $action)->whereNumber(['asset', 'file'])
                    ->name('admin.api.dam.assets.'.$name.'model-thumbnail.'.$action);
            }
            Route::post('', 'store')->whereNumber(['asset', 'file'])
                ->name('admin.api.dam.assets.'.$name.'model-thumbnail.store');
        });
    }

    Route::controller(AssetController::class)->prefix('assets')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.assets.index');
        Route::put('/edit/{id}', 'edit')->name('admin.api.dam.assets.edit');
        Route::get('/{id}', 'show')->name('admin.api.dam.assets.show');
        Route::post('/reupload', 'reUpload')->name('admin.api.dam.assets.reUpload');
        Route::get('/{id}/metadata', 'metadata')->whereNumber('id')->name('admin.api.dam.assets.metadata');
        Route::put('/{id}', 'update')->name('admin.api.dam.assets.update');
        Route::post('', 'upload')->name('admin.api.dam.assets.upload');
        Route::delete('/{id}', 'destroy')->name('admin.api.dam.assets.destroy');
        Route::get('/download/{id}', 'download')->name('admin.api.dam.assets.download');
    });

    Route::controller(AssetRelationshipController::class)->prefix('assets')->group(function () {
        Route::get('/{asset}/relationships', 'index')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.relationships.index');
        Route::post('/{asset}/relationships', 'store')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.relationships.store');
        Route::delete('/{asset}/relationships/{relationship}', 'destroy')
            ->whereNumber(['asset', 'relationship'])
            ->name('admin.api.dam.assets.relationships.destroy');
        Route::get('/{asset}/files', 'files')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.files.index');
    });

    // P4 D14: related assets, ranked live from content signals among what the caller may list.
    Route::get('assets/{asset}/related', [RelatedAssetController::class, 'index'])
        ->whereNumber('asset')
        ->name('admin.api.dam.assets.related');

    // P3a S3/S5: the Files tab's attach, remove, promote, edit, reorder, history and restore, and the stack basis/key.
    Route::controller(AttachmentController::class)->prefix('assets')->group(function () {
        Route::post('/{asset}/files', 'store')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.files.store');
        Route::delete('/{asset}/files/{file}', 'destroy')
            ->whereNumber(['asset', 'file'])
            ->name('admin.api.dam.assets.files.destroy');
        Route::put('/{asset}/attachments/order', 'reorder')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.attachments.order');
        Route::post('/{asset}/attachments/{attachment}/promote', 'promote')
            ->whereNumber(['asset', 'attachment'])
            ->name('admin.api.dam.assets.attachments.promote');
        Route::patch('/{asset}/attachments/{attachment}', 'update')
            ->whereNumber(['asset', 'attachment'])
            ->name('admin.api.dam.assets.attachments.update');
        Route::get('/{asset}/attachments/{attachment}/revisions', 'revisions')
            ->whereNumber(['asset', 'attachment'])
            ->name('admin.api.dam.assets.attachments.revisions');
        Route::post('/{asset}/attachments/{attachment}/revisions/{revision}/restore', 'restore')
            ->whereNumber(['asset', 'attachment', 'revision'])
            ->name('admin.api.dam.assets.attachments.restore');
        Route::put('/{asset}/stack', 'stack')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.stack.update');
    });

    // P3b-1 (D7): merge existing assets into a stack, then split or finalize, as in the admin UI.
    Route::controller(MergeController::class)->prefix('assets')->group(function () {
        Route::get('/{asset}/merges', 'index')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.merges.index');
        Route::get('/{asset}/merge/preview', 'preview')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.merges.preview');
        Route::post('/{asset}/merge', 'store')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.merges.store');
        Route::get('/{asset}/merges/{merge}/split', 'splitPreview')
            ->whereNumber(['asset', 'merge'])
            ->name('admin.api.dam.assets.merges.split_preview');
        Route::post('/{asset}/merges/{merge}/split', 'split')
            ->whereNumber(['asset', 'merge'])
            ->name('admin.api.dam.assets.merges.split');
        Route::post('/{asset}/merges/{merge}/finalize', 'finalize')
            ->whereNumber(['asset', 'merge'])
            ->name('admin.api.dam.assets.merges.finalize');
        Route::post('/{asset}/merges/{merge}/revoke-links', 'revokeLinks')
            ->whereNumber(['asset', 'merge'])
            ->name('admin.api.dam.assets.merges.revoke_links');
    });

    // P3b-2: "Stack instead" for an upload's exact-duplicate notice, and the stack suggestions list.
    Route::controller(DuplicateController::class)->group(function () {
        Route::post('assets/{asset}/stack-instead', 'stackInstead')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.stack-instead');
        Route::get('stack-suggestions', 'suggestions')
            ->name('admin.api.dam.stack-suggestions.index');
    });

    // P8′ (D25): templates, the REST twin of the Templates pages (the locked layout is edited on the web only).
    Route::controller(TemplateController::class)->prefix('templates')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.templates.index');
        Route::post('', 'store')->name('admin.api.dam.templates.store');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.templates.show');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.templates.update');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.templates.destroy');
        Route::post('/{id}/publish', 'publish')->whereNumber('id')->name('admin.api.dam.templates.publish');
        Route::post('/{id}/archive', 'archive')->whereNumber('id')->name('admin.api.dam.templates.archive');
        Route::post('/{id}/images', 'importImage')->whereNumber('id')->name('admin.api.dam.templates.images.store');
        Route::post('/{id}/assets', 'createAsset')->whereNumber('id')->name('admin.api.dam.templates.assets.store');
    });

    // P5c (D16): near-duplicate pairs, for the Library or one asset, and "Not duplicates".
    Route::controller(SimilarImageController::class)->group(function () {
        Route::get('similar-images', 'index')->name('admin.api.dam.similar.index');
        Route::get('assets/{asset}/similar', 'forAsset')->whereNumber('asset')->name('admin.api.dam.assets.similar');
        Route::post('similar-images/dismiss', 'dismiss')->name('admin.api.dam.similar.dismiss');
    });

    // P5c (D8): the AI vocabulary and the AI tag review queue.
    Route::controller(AiVocabularyController::class)->prefix('ai-vocabulary')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.ai-vocabulary.index');
        Route::post('', 'store')->name('admin.api.dam.ai-vocabulary.store');
        Route::put('/{term}', 'update')->where('term', '[a-z0-9][a-z0-9-]{0,63}')->name('admin.api.dam.ai-vocabulary.update');
    });

    Route::controller(AiTagReviewController::class)->prefix('ai-review')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.ai-review.index');
        Route::post('bulk', 'bulk')->name('admin.api.dam.ai-review.bulk');
    });

    // P6a: batch processing, the REST twin of the Library's Process dialog.
    Route::controller(ProcessingBatchController::class)->prefix('batches')->group(function () {
        Route::get('/recipes', 'recipes')->name('admin.api.dam.batches.recipes');
        Route::post('', 'store')->name('admin.api.dam.batches.store');
        Route::get('/{batch}', 'show')->whereNumber('batch')->name('admin.api.dam.batches.show');
        Route::post('/{batch}/items/{item}/retry', 'retry')->whereNumber(['batch', 'item'])->name('admin.api.dam.batches.items.retry');
        Route::post('/{batch}/cancel', 'cancel')->whereNumber('batch')->name('admin.api.dam.batches.cancel');
    });

    // P6 D26: tokenized embeds of a video or audio asset, and Retry for its preview proxy.
    Route::controller(AssetEmbedController::class)->prefix('assets')->group(function () {
        Route::get('/{asset}/embeds', 'index')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.embeds.index');
        Route::post('/{asset}/embeds', 'store')
            ->whereNumber('asset')
            ->name('admin.api.dam.assets.embeds.store');
        Route::post('/{asset}/embeds/{embed}/revoke', 'revoke')
            ->whereNumber(['asset', 'embed'])
            ->name('admin.api.dam.assets.embeds.revoke');
    });

    Route::post('assets/{asset}/video-proxy/retry', [VideoProxyController::class, 'retry'])
        ->whereNumber('asset')
        ->middleware('throttle:dam-video-proxy-retry')
        ->name('admin.api.dam.assets.video-proxy.retry');

    Route::controller(DirectoryController::class)->prefix('directories')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.directory.index');
        Route::get('{id}', 'getDirectory')->name('admin.api.dam.directory.get');
        Route::post('', 'store')->name('admin.api.dam.directory.store');
        Route::put('/{id}', 'update')->name('admin.api.dam.directory.update');
        Route::delete('{id}', 'destroy')->name('admin.api.dam.directory.delete');
    });

    // Move, copy and Trash: the Library's own actions, for agents that reorganize it.
    Route::controller(ReorganizeController::class)->group(function () {
        Route::post('assets/move', 'moveAssets')->name('admin.api.dam.assets.mass_move');
        Route::post('assets/{id}/move', 'moveAsset')->whereNumber('id')->name('admin.api.dam.assets.move');
        Route::post('assets/{id}/copy', 'copyAsset')->whereNumber('id')->name('admin.api.dam.assets.copy');
        Route::post('directories/{id}/move', 'moveDirectory')->whereNumber('id')->name('admin.api.dam.directory.move');
        Route::post('directories/{id}/copy', 'copyDirectory')->whereNumber('id')->name('admin.api.dam.directory.copy');
        Route::get('trash', 'trash')->name('admin.api.dam.trash.index');
        Route::post('trash/{id}/restore', 'restore')->whereNumber('id')->name('admin.api.dam.trash.restore');
        Route::delete('trash/{id}', 'purge')->whereNumber('id')->name('admin.api.dam.trash.destroy');
    });

    Route::controller(CommentController::class)->prefix('comments')->group(function () {
        Route::get('{id}', 'comments')->name('admin.api.dam.comment.get');
        Route::put('/{id}', 'update')->name('admin.api.dam.comment.update');
        Route::delete('/{id}', 'delete')->name('admin.api.dam.comment.delete');
        Route::post('', 'createComment')->name('admin.api.dam.comment.store');
        Route::put('/{id}/resolution', 'resolution')->whereNumber('id')->name('admin.api.dam.comment.resolution');
    });

    // P7 review: rounds, Watch and feedback requests.
    Route::controller(ReviewController::class)->prefix('assets')->group(function () {
        Route::get('/{asset}/review', 'show')->whereNumber('asset')->name('admin.api.dam.assets.review.show');
        Route::put('/{asset}/watch', 'watch')->whereNumber('asset')->name('admin.api.dam.assets.review.watch');
        Route::post('/{asset}/review-requests', 'storeRequest')->whereNumber('asset')->name('admin.api.dam.assets.review.requests.store');
        Route::post('/{asset}/review-requests/{request}/close', 'closeRequest')
            ->whereNumber(['asset', 'request'])
            ->name('admin.api.dam.assets.review.requests.close');
    });

    Route::controller(TagController::class)->prefix('tags')->group(function () {
        Route::get('', 'allTags')->name('admin.api.dam.tags.all');
        Route::post('/bulk', 'bulkAssign')->name('admin.api.dam.tags.bulk_assign');
        Route::get('{id}', 'tags')->whereNumber('id')->name('admin.api.dam.tags.get');
        Route::post('', 'addTag')->name('admin.api.dam.tag.add');
        Route::delete('', 'removeTag')->name('admin.api.dam.tag.delete');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.tags.destroy');
    });

    Route::controller(PropertyController::class)->prefix('properties')->group(function () {
        Route::get('{id}', 'properties')->name('admin.api.dam.property.get');
        Route::post('/{id}', 'addProperty')->name('admin.api.dam.property.add');
        Route::patch('/{id}', 'update')->name('admin.api.dam.property.update');
        Route::delete('/{id}', 'delete')->name('admin.api.dam.property.delete');
    });

    // P4a (D9): custom metadata field definitions.
    Route::controller(MetadataFieldController::class)->prefix('metadata-fields')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.metadata_fields.index');
        Route::post('', 'store')->name('admin.api.dam.metadata_fields.store');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.metadata_fields.update');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.metadata_fields.destroy');
    });

    // P5b (D18): embedded-metadata mappings.
    Route::controller(MetadataMappingController::class)->prefix('metadata-mappings')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.metadata_mappings.index');
        Route::post('', 'store')->name('admin.api.dam.metadata_mappings.store');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.metadata_mappings.show');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.metadata_mappings.update');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.metadata_mappings.destroy');
        Route::post('/{id}/dry-run', 'dryRun')->whereNumber('id')->name('admin.api.dam.metadata_mappings.dry_run');
        Route::post('/{id}/run', 'run')->whereNumber('id')->name('admin.api.dam.metadata_mappings.run');
    });

    // P5b (D19): automation rules, dry run, run-now and the run log.
    Route::controller(AutomationRuleController::class)->prefix('automation-rules')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.automation_rules.index');
        Route::post('', 'store')->name('admin.api.dam.automation_rules.store');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.automation_rules.show');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.automation_rules.update');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.automation_rules.destroy');
        Route::post('/{id}/dry-run', 'dryRun')->whereNumber('id')->name('admin.api.dam.automation_rules.dry_run');
        Route::post('/{id}/run', 'run')->whereNumber('id')->name('admin.api.dam.automation_rules.run');
        Route::get('/{id}/runs', 'runs')->whereNumber('id')->name('admin.api.dam.automation_rules.runs');
        Route::get('/{id}/runs/{run}', 'showRun')->whereNumber(['id', 'run'])->name('admin.api.dam.automation_rules.runs.show');
    });

    Route::controller(LinkedResourcesController::class)->prefix('linked-resource')->group(function () {
        Route::get('{id}', 'getLinkedResource')->name('admin.api.dam.linked_resource.get');
    });

    // P4 D2/D23: collections, their members, audience, link and slug; the same rules as the web routes.
    Route::controller(CollectionController::class)->prefix('collections')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.collections.index');
        Route::post('', 'store')->name('admin.api.dam.collections.store');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.collections.show');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.collections.update');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.collections.destroy');
        Route::get('/{id}/assets', 'members')->whereNumber('id')->name('admin.api.dam.collections.assets.index');
        Route::post('/{id}/assets', 'addMembers')->whereNumber('id')->name('admin.api.dam.collections.assets.store');
        Route::put('/{id}/assets/order', 'reorder')->whereNumber('id')->name('admin.api.dam.collections.assets.order');
        Route::delete('/{id}/assets/{assetId}', 'removeMember')->whereNumber(['id', 'assetId'])->name('admin.api.dam.collections.assets.destroy');
        Route::put('/{id}/audience', 'audience')->whereNumber('id')->name('admin.api.dam.collections.audience');
        Route::post('/{id}/link', 'createLink')->whereNumber('id')->name('admin.api.dam.collections.link.store');
    });

    Route::controller(ShareController::class)->prefix('shares')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.shares.index');
        Route::get('/slug-check', 'slugCheck')->name('admin.api.dam.shares.slug_check');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.shares.show');
        Route::post('', 'store')->name('admin.api.dam.shares.store');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.shares.update');
        Route::post('/{id}/revoke', 'revoke')->whereNumber('id')->name('admin.api.dam.shares.revoke');
        Route::post('/{id}/reauthorize', 'reauthorize')->whereNumber('id')->name('admin.api.dam.shares.reauthorize');
        Route::post('/{id}/reissue', 'reissue')->whereNumber('id')->name('admin.api.dam.shares.reissue');
        Route::delete('/{id}', 'destroy')->whereNumber('id')->name('admin.api.dam.shares.destroy');
    });

    // P5a (D1): guest upload requests, staff only. The guest's portal (`/u/{token}`) is never part of the API.
    Route::controller(UploadRequestController::class)->prefix('upload-requests')->group(function () {
        Route::get('', 'index')->name('admin.api.dam.upload_requests.index');
        Route::post('', 'store')->name('admin.api.dam.upload_requests.store');
        Route::get('/{id}', 'show')->whereNumber('id')->name('admin.api.dam.upload_requests.show');
        Route::put('/{id}', 'update')->whereNumber('id')->name('admin.api.dam.upload_requests.update');
        Route::post('/{id}/close', 'close')->whereNumber('id')->name('admin.api.dam.upload_requests.close');
        Route::post('/{id}/reissue', 'reissue')->whereNumber('id')->name('admin.api.dam.upload_requests.reissue');
        Route::get('/{id}/submissions', 'submissions')->whereNumber('id')->name('admin.api.dam.upload_requests.submissions');
        Route::post('/{id}/accept', 'accept')->whereNumber('id')->name('admin.api.dam.upload_requests.accept');
        Route::post('/{id}/reject', 'reject')->whereNumber('id')->name('admin.api.dam.upload_requests.reject');
    });
});
