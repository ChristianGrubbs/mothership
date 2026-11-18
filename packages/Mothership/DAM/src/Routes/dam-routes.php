<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\DAM\Http\Controllers\ActionRequestController;
use Mothership\DAM\Http\Controllers\AiTagReviewController;
use Mothership\DAM\Http\Controllers\AiVocabularyController;
use Mothership\DAM\Http\Controllers\AnalyticsIngestController;
use Mothership\DAM\Http\Controllers\AnalyticsPrivacyController;
use Mothership\DAM\Http\Controllers\AnalyticsReportController;
use Mothership\DAM\Http\Controllers\Asset\AiRenameController;
use Mothership\DAM\Http\Controllers\Asset\AiTagController;
use Mothership\DAM\Http\Controllers\Asset\AssetAvailabilityController;
use Mothership\DAM\Http\Controllers\Asset\AssetController;
use Mothership\DAM\Http\Controllers\Asset\AssetEmbedController;
use Mothership\DAM\Http\Controllers\Asset\AssetFieldController;
use Mothership\DAM\Http\Controllers\Asset\AssetFileController;
use Mothership\DAM\Http\Controllers\Asset\AssetMergeController;
use Mothership\DAM\Http\Controllers\Asset\AssetOptimizationController;
use Mothership\DAM\Http\Controllers\Asset\AssetRelationshipController;
use Mothership\DAM\Http\Controllers\Asset\AssetStackController;
use Mothership\DAM\Http\Controllers\Asset\AssetVectorizationController;
use Mothership\DAM\Http\Controllers\Asset\ChunkUploadController;
use Mothership\DAM\Http\Controllers\Asset\CommentController;
use Mothership\DAM\Http\Controllers\Asset\DuplicateController;
use Mothership\DAM\Http\Controllers\Asset\LinkedResourcesController;
use Mothership\DAM\Http\Controllers\Asset\PanoramaPreviewController;
use Mothership\DAM\Http\Controllers\Asset\ProcessingBatchController;
use Mothership\DAM\Http\Controllers\Asset\PropertyController;
use Mothership\DAM\Http\Controllers\Asset\RelatedAssetController;
use Mothership\DAM\Http\Controllers\Asset\ReviewController;
use Mothership\DAM\Http\Controllers\Asset\ShareController;
use Mothership\DAM\Http\Controllers\Asset\TagController;
use Mothership\DAM\Http\Controllers\Asset\UploadController;
use Mothership\DAM\Http\Controllers\Asset\VideoProxyController;
use Mothership\DAM\Http\Controllers\AssetPickerController;
use Mothership\DAM\Http\Controllers\AutomationRuleController;
use Mothership\DAM\Http\Controllers\CaptureConfigurationController;
use Mothership\DAM\Http\Controllers\CollectionController;
use Mothership\DAM\Http\Controllers\ConfigurationController;
use Mothership\DAM\Http\Controllers\CustomThumbnailController;
use Mothership\DAM\Http\Controllers\DAMController;
use Mothership\DAM\Http\Controllers\DirectoryController;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Http\Controllers\HubResourceController;
use Mothership\DAM\Http\Controllers\ImageEditController;
use Mothership\DAM\Http\Controllers\ImageEditorController;
use Mothership\DAM\Http\Controllers\MetadataFieldController;
use Mothership\DAM\Http\Controllers\MetadataMappingController;
use Mothership\DAM\Http\Controllers\ModelFileThumbnailController;
use Mothership\DAM\Http\Controllers\ModelPrimaryViewController;
use Mothership\DAM\Http\Controllers\ModelThumbnailController;
use Mothership\DAM\Http\Controllers\ReplayRelayController;
use Mothership\DAM\Http\Controllers\SecurityReportController;
use Mothership\DAM\Http\Controllers\SimilarImageController;
use Mothership\DAM\Http\Controllers\StackSuggestionController;
use Mothership\DAM\Http\Controllers\TagController as TagManagementController;
use Mothership\DAM\Http\Controllers\Template\TemplateController;
use Mothership\DAM\Http\Controllers\TransfersController;
use Mothership\DAM\Http\Controllers\TrashController;
use Mothership\DAM\Http\Controllers\UploadRequestController;
use Mothership\DAM\Http\Controllers\WebCollectionController;
use Mothership\DAM\Http\Controllers\WebPageController;
use Mothership\DAM\Http\Middleware\ReadOnlySession;
use Mothership\DAM\Http\Middleware\SignalDownloadStart;

Route::group([
    'middleware' => ['admin', 'dam'],
    'prefix'     => config('app.admin_url').'/dam',
], function () {
    Route::controller(DAMController::class)->prefix('')->group(function () {
        Route::get('', 'index')->name('admin.dam.index');
    });

    Route::get('pages', [WebPageController::class, 'index'])->name('admin.dam.pages.index');
    Route::get('pages/listing', [WebPageController::class, 'listing'])->name('admin.dam.pages.listing');
    Route::get('pages/{id}/grant', [WebPageController::class, 'grant'])->where('id', '[0-9]+')->name('admin.dam.pages.grant');
    Route::get('pages/{id}/open', [WebPageController::class, 'show'])->where('id', '[0-9]+')->name('admin.dam.pages.show');
    Route::get('pages/{id}/members', [WebPageController::class, 'members'])->where('id', '[0-9]+')->name('admin.dam.pages.members');
    Route::delete('pages/{id}', [WebPageController::class, 'destroy'])->whereNumber('id')->name('admin.dam.pages.destroy');
    Route::controller(WebCollectionController::class)->prefix('pages')->group(function () {
        Route::post('collections', 'store')->name('admin.dam.pages.collections.store');
        Route::patch('collections/{id}', 'update')->where('id', '[0-9]+')->name('admin.dam.pages.collections.update');
        Route::delete('collections/{id}', 'destroy')->where('id', '[0-9]+')->name('admin.dam.pages.collections.destroy');
        Route::post('move', 'move')->name('admin.dam.pages.move');
    });

    // P8′ (D25): templates. Authoring routes map to dam.template.manage, filling routes to dam.template.use, and the
    // shared reads to either (Config/acl.php `also_authorizes`).
    Route::controller(TemplateController::class)->prefix('templates')->group(function () {
        Route::get('', 'index')->name('admin.dam.templates.index');
        Route::get('list', 'list')->name('admin.dam.templates.list');
        Route::post('', 'store')->name('admin.dam.templates.store');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.dam.templates.show');
        Route::get('{id}/edit', 'edit')->whereNumber('id')->name('admin.dam.templates.edit');
        Route::put('{id}', 'update')->whereNumber('id')->name('admin.dam.templates.update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.dam.templates.destroy');
        Route::post('{id}/publish', 'publish')->whereNumber('id')->name('admin.dam.templates.publish');
        Route::post('{id}/archive', 'archive')->whereNumber('id')->name('admin.dam.templates.archive');
        Route::post('{id}/images', 'importImage')->whereNumber('id')->name('admin.dam.templates.images.store');
        Route::get('{id}/layout', 'layout')->whereNumber('id')->name('admin.dam.templates.layout');
        Route::post('{id}/layout/save', 'layoutSave')->whereNumber('id')->name('admin.dam.templates.layout.save');
        Route::get('{id}/resources/{resource}', 'resource')->whereNumber('id')->whereUuid('resource')->name('admin.dam.templates.resource');
        Route::post('{id}/preview', 'preview')->whereNumber('id')->name('admin.dam.templates.preview');
        Route::get('{id}/use', 'use')->whereNumber('id')->name('admin.dam.templates.use');
        Route::post('{id}/use/preview', 'usePreview')->whereNumber('id')->name('admin.dam.templates.use.preview');
        Route::post('{id}/assets', 'createAsset')->whereNumber('id')->name('admin.dam.templates.assets.store');
    });

    Route::controller(TransfersController::class)->prefix('hub/transfers')->group(function () {
        Route::get('', 'index')->name('admin.dam.transfers.index');
        // Background grant renewals must not spend the authenticated user's Home action budget.
        Route::post('grant', 'grant')->middleware('throttle:120,1,dam-transfers:')->name('admin.dam.transfers.grant');
    });

    // Hub resources (ADR 0015): the fonts and brushes people upload for the Craft apps, the manager page included.
    Route::controller(HubResourceController::class)->prefix('hub/resources')->group(function () {
        Route::get('', 'index')->name('admin.dam.hub.resources');
        Route::get('list', 'list')->name('admin.dam.hub.resources.list');
        Route::post('', 'store')->middleware('throttle:60,1')->name('admin.dam.hub.resources.store');
        Route::patch('{uuid}', 'update')->whereUuid('uuid')->name('admin.dam.hub.resources.update');
        Route::delete('{uuid}', 'destroy')->whereUuid('uuid')->name('admin.dam.hub.resources.destroy');
        Route::get('{uuid}/bytes', 'bytes')->whereUuid('uuid')->name('admin.dam.hub.resources.bytes');
    });

    // P6a: batch processing (the Library's Process dialog and the viewer's Export menu).
    Route::controller(ProcessingBatchController::class)->prefix('batches')->group(function () {
        Route::get('recipes', 'recipes')->name('admin.dam.batches.recipes');
        Route::post('', 'store')->name('admin.dam.batches.store');
        Route::get('{batch}', 'show')->whereNumber('batch')->name('admin.dam.batches.show');
        Route::post('{batch}/items/{item}/retry', 'retry')->whereNumber(['batch', 'item'])->name('admin.dam.batches.items.retry');
        Route::post('{batch}/cancel', 'cancel')->whereNumber('batch')->name('admin.dam.batches.cancel');
    });

    Route::group(['prefix' => 'assets'], function () {
        Route::get('vectorization/{id}', [AssetVectorizationController::class, 'state'])
            ->whereNumber('id')->name('admin.dam.assets.vectorization.state');
        Route::post('vectorization/{id}', [AssetVectorizationController::class, 'store'])
            ->whereNumber('id')->name('admin.dam.assets.vectorization.store');

        Route::get('optimization/{id}', [AssetOptimizationController::class, 'state'])
            ->whereNumber('id')->name('admin.dam.assets.optimization.state');
        Route::post('optimization/{id}', [AssetOptimizationController::class, 'store'])
            ->whereNumber('id')->name('admin.dam.assets.optimization.store');

        Route::controller(AssetController::class)->group(function () {
            Route::get('', 'index')->name('admin.dam.assets.index');
            Route::get('/edit/{id}', 'edit')->name('admin.dam.assets.edit')->where('id', '[0-9]+');
            Route::get('/info/{id}', 'info')->name('admin.dam.assets.info')->where('id', '[0-9]+');
            Route::get('open/{id}', 'open')->name('admin.dam.assets.open')->where('id', '[0-9]+');
            Route::get('show/{id}', 'show')->name('admin.dam.assets.show');
            Route::put('update/{id}', 'update')->name('admin.dam.assets.update');

            Route::post('/upload', 'upload')->name('admin.dam.assets.upload');
            Route::post('/upload-folder', 'uploadFolder')->name('admin.dam.assets.upload_folder');
            Route::post('/re-upload', 'reUpload')->name('admin.dam.assets.re_upload');
            Route::delete('/destroy/{id}', 'destroy')->name('admin.dam.assets.destroy');
            Route::post('/mass-update', 'massUpdate')->name('admin.dam.assets.mass_update');
            Route::post('/mass-delete', 'massDestroy')->name('admin.dam.assets.mass_delete');

            Route::get('download/{id}', 'download')->name('admin.dam.assets.download')->middleware(SignalDownloadStart::class);
            Route::get('download-compressed/{id}', 'downloadCompressed')->name('admin.dam.assets.download_compressed')->middleware(SignalDownloadStart::class);
            Route::get('custom-download/{id}', 'customDownload')->name('admin.dam.assets.custom_download')->middleware(SignalDownloadStart::class);

            Route::post('rename', 'rename')->name('admin.dam.assets.rename');
            Route::post('/moved', 'moved')->name('admin.dam.assets.moved');

            Route::get('metadata/{id}', 'getMetadataById')->name('admin.dam.assets.metadata')->where('id', '[0-9]+');

        });

        Route::controller(UploadController::class)->prefix('upload')->group(function () {
            Route::post('/tracker', 'startSession')->name('admin.dam.assets.upload.tracker');
            Route::get('/stats/{uuid}', 'stats')->name('admin.dam.assets.upload.stats');
            Route::post('/pause/{uuid}', 'pause')->name('admin.dam.assets.upload.pause');
            Route::post('/resume/{uuid}', 'resume')->name('admin.dam.assets.upload.resume');
            Route::post('/cancel/{uuid}', 'cancel')->name('admin.dam.assets.upload.cancel');
            Route::post('/retry/{uuid}', 'retry')->name('admin.dam.assets.upload.retry');
            Route::post('/complete/{uuid}', 'complete')->name('admin.dam.assets.upload.complete');
        });

        Route::controller(ChunkUploadController::class)->prefix('upload/chunks')->group(function () {
            Route::post('/', 'initialize')->name('admin.dam.assets.chunk-upload.initialize');
            Route::get('/{uuid}', 'status')->whereUuid('uuid')->name('admin.dam.assets.chunk-upload.status');
            Route::put('/{uuid}/{index}', 'store')->whereUuid('uuid')->whereNumber('index')->name('admin.dam.assets.chunk-upload.store');
            Route::post('/{uuid}/complete', 'complete')->whereUuid('uuid')->name('admin.dam.assets.chunk-upload.complete');
            Route::delete('/{uuid}', 'cancel')->whereUuid('uuid')->name('admin.dam.assets.chunk-upload.cancel');
        });

        Route::controller(TagController::class)->prefix('')->group(function () {
            Route::post('/tag', 'addOrUpdateTag')->name('admin.dam.assets.tag');
            Route::post('/remove-tag', 'removeTag')->name('admin.dam.assets.remove-tag');
            Route::post('/mass-assign-tags', 'massAssignTags')->name('admin.dam.assets.mass_assign_tags');
        });

        // P4a (D9): typed field values from the modal's Details editor and the bulk "Edit fields" action.
        Route::controller(AssetFieldController::class)->group(function () {
            Route::put('/{id}/fields', 'update')->whereNumber('id')->name('admin.dam.assets.fields.update');
            Route::post('/mass-update-fields', 'massUpdate')->name('admin.dam.assets.mass_update_fields');
        });

        Route::controller(AiTagController::class)->prefix('ai-tags')->group(function () {
            Route::post('{id}/accept', 'accept')->whereNumber('id')->name('admin.dam.assets.ai-tags.accept');
            Route::post('{id}/dismiss', 'dismiss')->whereNumber('id')->name('admin.dam.assets.ai-tags.dismiss');
            Route::post('{id}/accept-all', 'acceptAll')->whereNumber('id')->name('admin.dam.assets.ai-tags.accept-all');
            Route::post('{id}/describe', 'describe')->whereNumber('id')->name('admin.dam.assets.ai-tags.describe');
            Route::get('{id}/files/{fileId}', 'member')->whereNumber(['id', 'fileId'])->name('admin.dam.assets.ai-tags.member');
            Route::post('{id}/files/{fileId}/describe', 'describeMember')->whereNumber(['id', 'fileId'])->name('admin.dam.assets.ai-tags.member.describe');
            Route::get('{id}', 'show')->whereNumber('id')->name('admin.dam.assets.ai-tags.show');
            Route::post('eligibility', 'eligibility')->name('admin.dam.assets.ai-tags.eligibility');
        });

        // Rename with AI: proposes one file's name at a time; the ordinary rename applies it.
        Route::post('ai-rename/suggest', [AiRenameController::class, 'suggest'])->name('admin.dam.assets.ai-rename.suggest');
        Route::post('ai-rename/members', [AiRenameController::class, 'members'])->name('admin.dam.assets.ai-rename.members');
        Route::post('ai-rename/apply-member', [AiRenameController::class, 'applyMember'])->name('admin.dam.assets.ai-rename.apply-member');

        Route::controller(PropertyController::class)->prefix('')->group(function () {
            Route::get('/edit/{id}/properties', 'properties')->name('admin.dam.asset.properties.index')->where('id', '[0-9]+');
            Route::post('/edit/{id}/properties/create', 'propertiesCreate')->name('admin.dam.asset.property.store');
            Route::put('/edit/{id}/properties/update', 'propertiesUpdate')->name('admin.dam.asset.properties.update');
            Route::get('edit/properties/edit/{id}', 'propertiesEdit')->name('admin.dam.asset.property.edit');
            Route::delete('edit/{asset_id}/properties/destroy/{id}', 'propertiesDestroy')->name('admin.dam.asset.properties.delete');
            Route::post('/edit/{asset_id}/properties/mass-delete', 'massDestroy')->name('admin.dam.asset.properties.mass_delete');
        });

        Route::controller(CommentController::class)->prefix('')->group(function () {
            Route::get('/get-user-info/{id}', 'getUserInfo')->name('admin.dam.asset.comments.get_user_info')->where('id', '[0-9]+');
            Route::get('/edit/{id}/comments', 'comments')->name('admin.dam.asset.comments.index')->where('id', '[0-9]+');
            Route::post('/edit/{id}/comment/create', 'commentCreate')->name('admin.dam.asset.comment.store');
            Route::put('/edit/{id}/comment/update', 'commentUpdate')->name('admin.dam.asset.comment.update');
            Route::delete('edit/{id}/comment/delete', 'commentDelete')->name('admin.dam.asset.comment.delete');
            Route::put('/edit/{id}/comment/{comment}/resolution', 'resolution')
                ->whereNumber(['id', 'comment'])->name('admin.dam.asset.comment.resolution');
        });

        // P7 review: the Comments tab's rounds, Watch, feedback requests and the people picker.
        Route::controller(ReviewController::class)->prefix('')->group(function () {
            Route::get('/review/people', 'people')->name('admin.dam.asset.review.people');
            Route::get('/edit/{id}/review', 'show')->whereNumber('id')->name('admin.dam.asset.review.show');
            Route::put('/edit/{id}/watch', 'watch')->whereNumber('id')->name('admin.dam.asset.review.watch');
            Route::post('/edit/{id}/review-requests', 'storeRequest')->whereNumber('id')->name('admin.dam.asset.review.requests.store');
            Route::post('/edit/{id}/review-requests/{request}/close', 'closeRequest')
                ->whereNumber(['id', 'request'])->name('admin.dam.asset.review.requests.close');
        });

        Route::post('/{id}/custom-thumbnail', [CustomThumbnailController::class, 'store'])
            ->whereNumber('id')->name('admin.dam.assets.custom_thumbnail.store');

        // Browser-captured 3D thumbnails (ADR 0006): same group, same middleware
        // and the same edit-level gate as the custom thumbnail it never replaces.
        Route::controller(ModelThumbnailController::class)->prefix('{id}/model-thumbnail')->where(['id' => '[0-9]+'])->group(function () {
            Route::get('/', 'show')->name('admin.dam.assets.model_thumbnail.show');
            Route::post('/', 'store')->name('admin.dam.assets.model_thumbnail.store');
        });

        // The same handshake for a 3D file inside a stack, keyed by the file so a capture never lands on the
        // stack's cover. The owning asset is resolved server-side from the file.
        Route::controller(ModelFileThumbnailController::class)->prefix('files/{assetFile}/model-thumbnail')->where(['assetFile' => '[0-9]+'])->group(function () {
            Route::get('/', 'show')->name('admin.dam.assets.file_model_thumbnail.show');
            Route::post('/', 'store')->name('admin.dam.assets.file_model_thumbnail.store');
        });

        Route::controller(ModelPrimaryViewController::class)->prefix('primary-view/{id}')->where(['id' => '[0-9]+'])->group(function () {
            Route::get('/', 'show')->name('admin.dam.assets.primary_view.show');
            Route::post('/', 'save')->name('admin.dam.assets.primary_view.save');
            Route::delete('/', 'restore')->name('admin.dam.assets.primary_view.restore');
        });

        Route::controller(ImageEditorController::class)->prefix('editor/{id}')->where(['id' => '[0-9]+'])->group(function () {
            Route::get('/', 'document')->name('admin.dam.assets.editor.document');
            Route::post('/save', 'save')->name('admin.dam.assets.editor.save');
            Route::post('/resources', 'upload')->name('admin.dam.assets.editor.upload');
            Route::get('/resources/{resource}', 'resource')->name('admin.dam.assets.editor.resource')->whereUuid('resource');
        });

        Route::controller(ImageEditController::class)->prefix('image-edit')->group(function () {
            Route::post('/resize/{id}', 'resize')->name('admin.dam.assets.image_edit.resize')->where('id', '[0-9]+');
            Route::post('/adjust/{id}', 'adjust')->name('admin.dam.assets.image_edit.adjust')->where('id', '[0-9]+');
            Route::post('/transform/{id}', 'transform')->name('admin.dam.assets.image_edit.transform')->where('id', '[0-9]+');
            Route::post('/bg-color/{id}', 'bgColor')->middleware(EnsurePimEnabled::class)->name('admin.dam.assets.image_edit.bg_color')->where('id', '[0-9]+');
            Route::post('/bg-upload/{id}', 'bgUpload')->middleware(EnsurePimEnabled::class)->name('admin.dam.assets.image_edit.bg_upload')->where('id', '[0-9]+');
            Route::post('/bg-ai/{id}', 'bgAi')->middleware(EnsurePimEnabled::class)->name('admin.dam.assets.image_edit.bg_ai')->where('id', '[0-9]+');
            Route::post('/bg-preview/{id}', 'bgPreview')->name('admin.dam.assets.image_edit.bg_preview')->where('id', '[0-9]+');
            Route::post('/bg-color-normal/{id}', 'bgColorNormal')->name('admin.dam.assets.image_edit.bg_color_normal')->where('id', '[0-9]+');
            Route::post('/filters/{id}', 'filters')->name('admin.dam.assets.image_edit.filters')->where('id', '[0-9]+');
        });

        Route::controller(LinkedResourcesController::class)->prefix('linked-resources')->group(function () {
            Route::get('', 'index')->name('admin.dam.asset.linked_resources.index');
        });

        Route::post('/{asset}/files', [AssetFileController::class, 'store'])
            ->name('admin.dam.assets.files.store')->where('asset', '[0-9]+');
        Route::post('/{asset}/files/chunks', [AssetFileController::class, 'initializeChunked'])
            ->name('admin.dam.assets.files.chunks')->where('asset', '[0-9]+');
        Route::delete('/{asset}/files/{assetFile}', [AssetFileController::class, 'destroy'])
            ->name('admin.dam.assets.files.destroy')->where(['asset' => '[0-9]+', 'assetFile' => '[0-9]+']);
        Route::put('/{asset}/attachments/order', [AssetFileController::class, 'reorder'])
            ->name('admin.dam.assets.attachments.order')->where('asset', '[0-9]+');
        Route::post('/{asset}/attachments/{attachment}/promote', [AssetFileController::class, 'promote'])
            ->name('admin.dam.assets.attachments.promote')->where(['asset' => '[0-9]+', 'attachment' => '[0-9]+']);
        Route::patch('/{asset}/attachments/{attachment}', [AssetFileController::class, 'update'])
            ->name('admin.dam.assets.attachments.update')->where(['asset' => '[0-9]+', 'attachment' => '[0-9]+']);
        Route::post('/{asset}/attachments/{attachment}/revisions/{revision}/restore', [AssetFileController::class, 'restore'])
            ->name('admin.dam.assets.attachments.restore')->where(['asset' => '[0-9]+', 'attachment' => '[0-9]+', 'revision' => '[0-9]+']);
        Route::put('/{asset}/stack', [AssetStackController::class, 'update'])
            ->name('admin.dam.assets.stack.update')->where('asset', '[0-9]+');

        // P3b-1 (D7): merge existing assets into this one as a stack, then split or finalize each merge.
        Route::controller(AssetMergeController::class)->group(function () {
            Route::get('/{asset}/merges', 'index')
                ->name('admin.dam.assets.merges.index')->where('asset', '[0-9]+');
            Route::get('/{asset}/merge/preview', 'preview')
                ->name('admin.dam.assets.merges.preview')->where('asset', '[0-9]+');
            Route::post('/{asset}/merge', 'store')
                ->name('admin.dam.assets.merges.store')->where('asset', '[0-9]+');
            Route::get('/{asset}/merges/{merge}/split', 'splitPreview')
                ->name('admin.dam.assets.merges.split_preview')->where(['asset' => '[0-9]+', 'merge' => '[0-9]+']);
            Route::post('/{asset}/merges/{merge}/split', 'split')
                ->name('admin.dam.assets.merges.split')->where(['asset' => '[0-9]+', 'merge' => '[0-9]+']);
            Route::post('/{asset}/merges/{merge}/finalize', 'finalize')
                ->name('admin.dam.assets.merges.finalize')->where(['asset' => '[0-9]+', 'merge' => '[0-9]+']);
            Route::post('/{asset}/merges/{merge}/revoke-links', 'revokeLinks')
                ->name('admin.dam.assets.merges.revoke_links')->where(['asset' => '[0-9]+', 'merge' => '[0-9]+']);
        });

        // P3b-2: an upload's exact-duplicate notice, "Stack instead".
        Route::post('/{asset}/stack-instead', [DuplicateController::class, 'stackInstead'])
            ->name('admin.dam.assets.stack-instead')->where('asset', '[0-9]+');
        Route::put('/{asset}/availability', [AssetAvailabilityController::class, 'update'])
            ->name('admin.dam.assets.availability.update')->where('asset', '[0-9]+');

        // P4 D14: the Related section, a live ranking (never stored as relationships).
        Route::get('/{asset}/related', [RelatedAssetController::class, 'index'])
            ->name('admin.dam.assets.related')->where('asset', '[0-9]+');

        // P6 D26: tokenized embeds of a video or audio asset, and the preview proxy's status and Retry.
        Route::controller(AssetEmbedController::class)->group(function () {
            Route::get('/{asset}/embeds', 'index')
                ->name('admin.dam.assets.embeds.index')->where('asset', '[0-9]+');
            Route::post('/{asset}/embeds', 'store')
                ->name('admin.dam.assets.embeds.store')->where('asset', '[0-9]+');
            Route::post('/{asset}/embeds/{embed}/revoke', 'revoke')
                ->name('admin.dam.assets.embeds.revoke')->where(['asset' => '[0-9]+', 'embed' => '[0-9]+']);
        });

        Route::controller(VideoProxyController::class)->group(function () {
            Route::get('/{asset}/video-proxy', 'state')
                ->name('admin.dam.assets.video-proxy.state')->where('asset', '[0-9]+');
            Route::post('/{asset}/video-proxy/retry', 'retry')
                ->middleware('throttle:dam-video-proxy-retry')
                ->name('admin.dam.assets.video-proxy.retry')->where('asset', '[0-9]+');
        });

        Route::controller(AssetRelationshipController::class)->group(function () {
            Route::get('/{asset}/relationships', 'index')
                ->name('admin.dam.assets.relationships.index')
                ->where('asset', '[0-9]+');
            Route::post('/{asset}/relationships', 'store')
                ->name('admin.dam.assets.relationships.store')
                ->where('asset', '[0-9]+');
            Route::delete('/{asset}/relationships/{relationship}', 'destroy')
                ->name('admin.dam.assets.relationships.destroy')
                ->where(['asset' => '[0-9]+', 'relationship' => '[0-9]+']);
            Route::get('/{asset}/files', 'files')
                ->name('admin.dam.assets.files.index')
                ->where('asset', '[0-9]+');
            Route::get('/{asset}/attachments/{attachment}/revisions', 'revisions')
                ->name('admin.dam.assets.attachments.revisions')
                ->where(['asset' => '[0-9]+', 'attachment' => '[0-9]+']);
        });

        Route::controller(PanoramaPreviewController::class)
            ->prefix('/{asset}/panorama-preview')
            ->name('admin.dam.assets.panorama.')
            ->group(function () {
                Route::get('', 'state')->name('state');
                Route::post('', 'build')->name('build');
                Route::post('/rebuild', 'rebuild')->name('rebuild');
                Route::post('/retry', 'retry')->name('retry');
                Route::post('/cancel', 'cancel')->name('cancel');
                Route::patch('/mode', 'mode')->name('mode');
            });
    });

    Route::get('model-thumbnails/missing', [ModelThumbnailController::class, 'missing'])
        ->name('admin.dam.model_thumbnails.missing');

    Route::controller(FileController::class)->prefix('file')->group(function () {
        Route::get('/fetch/{path}', 'fetchFile')->where('path', '^assets/.*')->name('admin.dam.file.fetch')->withoutMiddleware(['admin', 'dam']);
        Route::get('/asset/{asset}/files/{assetFile}', 'fetchAssetFile')
            ->where(['asset' => '[0-9]+', 'assetFile' => '[0-9]+'])
            ->name('admin.dam.file.asset-file')
            ->middleware(SignalDownloadStart::class);
        Route::get('/asset/{asset}/files-zip', 'fetchAssetFilesZip')
            ->where('asset', '[0-9]+')
            ->name('admin.dam.file.asset-files-zip')
            ->middleware(SignalDownloadStart::class);
        Route::get('/thumbnail', 'thumbnail')->name('admin.dam.file.thumbnail');
        Route::get('/preview/', 'preview')->name('admin.dam.file.preview');
        Route::get('/cover-art/{assetId}', 'coverArt')->name('admin.dam.file.cover-art');
    });

    // Staff-only security reports (P1a); both map to dam.reports.security in Config/acl.php.
    Route::controller(SecurityReportController::class)->prefix('reports')->group(function () {
        Route::get('login-access', 'loginAccess')->name('admin.dam.reports.login-access');
        Route::get('operational-health', 'operationalHealth')->name('admin.dam.reports.operational-health');
    });

    // Product analytics (P1b): dashboards map to dam.reports.view, journeys to dam.reports.journeys.
    Route::controller(AnalyticsReportController::class)->prefix('reports')->group(function () {
        Route::get('engagement', 'engagement')->name('admin.dam.reports.engagement');
        Route::get('friction', 'friction')->name('admin.dam.reports.friction');
        Route::get('adoption', 'adoption')->name('admin.dam.reports.adoption');
        Route::get('journeys', 'journeys')->name('admin.dam.reports.journeys');
        Route::get('journeys/{session}', 'journey')->name('admin.dam.reports.journey')
            ->where('session', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
    });

    // The analytics notice and opt-out: every signed-in admin, so deliberately not in Config/acl.php.
    // Every signed-in admin may record only their own eligible session; no DAM capability grants another actor.
    // Keep normal session/CSRF middleware. This is a fixed SDK relay, not the capability-authenticated ingest route.
    // The session is read-only: a beacon that saved it would erase what a concurrent request just stored.
    Route::match(['GET', 'POST'], 'analytics/replay/{path?}', [ReplayRelayController::class, 'relay'])
        ->where('path', '.*')->name('admin.dam.analytics.replay')->middleware(['throttle:120,1,dam-replay:', ReadOnlySession::class]);

    Route::controller(AnalyticsPrivacyController::class)->prefix('analytics/privacy')->group(function () {
        Route::get('', 'show')->name('admin.dam.analytics.privacy');
        Route::post('', 'update')->name('admin.dam.analytics.privacy.update');
    });

    // The collector's ingest (P1b, §3.6.6): the signed capability in the body stands in for the CSRF token,
    // which a beacon cannot send.
    Route::post('t', [AnalyticsIngestController::class, 'admin'])->name('admin.dam.analytics.ingest')
        ->withoutMiddleware(PreventRequestForgery::class)
        ->middleware(['throttle:dam-telemetry', ReadOnlySession::class]);

    Route::controller(ShareController::class)->prefix('shared-links')->group(function () {
        Route::get('', 'index')->name('admin.dam.shared-links.index');
        // P4 D23: the share dialog's live check of a custom slug.
        Route::get('slug-check', 'slugCheck')->name('admin.dam.shared-links.slug_check')->middleware('throttle:60,1');
        Route::post('', 'store')->name('admin.dam.shared-links.store');
        // Share pages in folder: the observational candidate list; the links themselves are ordinary POSTs to `store`.
        Route::get('web-collections/{id}/candidates', 'webCollectionCandidates')->name('admin.dam.shared-links.web_pages_candidates')->where('id', '[0-9]+');
        Route::patch('{id}', 'update')->name('admin.dam.shared-links.update')->where('id', '[0-9]+');
        Route::patch('{id}/revoke', 'revoke')->name('admin.dam.shared-links.revoke')->where('id', '[0-9]+');
        Route::delete('{id}', 'destroy')->name('admin.dam.shared-links.destroy')->where('id', '[0-9]+');
        Route::patch('{id}/reauthorize', 'reauthorize')->name('admin.dam.shared-links.reauthorize')->where('id', '[0-9]+');
        Route::post('{id}/reissue', 'reissue')->name('admin.dam.shared-links.reissue')->where('id', '[0-9]+');
        Route::get('active/{type}/{targetId}', 'activeForTarget')
            ->name('admin.dam.shared-links.active_for_target')
            ->where('type', 'asset|directory')
            ->where('targetId', '[0-9]+');
    });

    // P5a (D1): guest upload requests. The guest's own routes are in upload-portal.php.
    Route::controller(UploadRequestController::class)->prefix('upload-requests')->group(function () {
        Route::get('', 'index')->name('admin.dam.upload-requests.index');
        Route::get('list', 'list')->name('admin.dam.upload-requests.list');
        Route::post('', 'store')->name('admin.dam.upload-requests.store');
        Route::get('{id}', 'show')->name('admin.dam.upload-requests.show')->where('id', '[0-9]+');
        Route::get('{id}/submissions', 'submissions')->name('admin.dam.upload-requests.submissions')->where('id', '[0-9]+');
        Route::patch('{id}', 'update')->name('admin.dam.upload-requests.update')->where('id', '[0-9]+');
        Route::patch('{id}/close', 'close')->name('admin.dam.upload-requests.close')->where('id', '[0-9]+');
        Route::post('{id}/reissue', 'reissue')->name('admin.dam.upload-requests.reissue')->where('id', '[0-9]+');
        Route::post('{id}/accept', 'accept')->name('admin.dam.upload-requests.accept')->where('id', '[0-9]+');
        Route::post('{id}/reject', 'reject')->name('admin.dam.upload-requests.reject')->where('id', '[0-9]+');
    });

    // P3b-2: the Library's suggested stacks.
    Route::controller(StackSuggestionController::class)->prefix('stack-suggestions')->group(function () {
        Route::get('', 'index')->name('admin.dam.stack-suggestions.index');
        Route::post('dismiss', 'dismiss')->name('admin.dam.stack-suggestions.dismiss');
        Route::post('apply', 'apply')->name('admin.dam.stack-suggestions.apply');
    });

    // P4 D2/D23: collections (ADR 0011). Reads are decided per collection (an audience member, its owner or a full
    // admin; anyone else gets 404), changes by the dam.collections.* ACL keys and managing the collection. Member reads
    // are the collection's own routes, the only ones where an audience grant counts (AccessContext::collection()).
    Route::controller(CollectionController::class)->prefix('collections')->group(function () {
        Route::get('', 'index')->name('admin.dam.collections.index');
        Route::get('list', 'list')->name('admin.dam.collections.list');
        Route::get('principals', 'principals')->name('admin.dam.collections.principals');
        Route::post('', 'store')->name('admin.dam.collections.store');
        Route::get('{id}', 'show')->whereNumber('id')->name('admin.dam.collections.show');
        Route::get('{id}/data', 'data')->whereNumber('id')->name('admin.dam.collections.data');
        Route::patch('{id}', 'update')->whereNumber('id')->name('admin.dam.collections.update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('admin.dam.collections.destroy');
        Route::put('{id}/audience', 'audience')->whereNumber('id')->name('admin.dam.collections.audience');
        Route::post('{id}/members', 'addMembers')->whereNumber('id')->name('admin.dam.collections.members.store');
        Route::put('{id}/members/order', 'reorder')->whereNumber('id')->name('admin.dam.collections.members.order');
        Route::delete('{id}/members/{assetId}', 'removeMember')->whereNumber(['id', 'assetId'])->name('admin.dam.collections.members.destroy');
        Route::post('{id}/link', 'createLink')->whereNumber('id')->name('admin.dam.collections.link.store');
        Route::get('{id}/zip', 'zip')->whereNumber('id')->name('admin.dam.collections.zip')->middleware(SignalDownloadStart::class);
        Route::get('{id}/members/{assetId}/thumbnail', 'memberThumbnail')->whereNumber(['id', 'assetId'])->name('admin.dam.collections.members.thumbnail');
        Route::get('{id}/members/{assetId}/preview', 'memberPreview')->whereNumber(['id', 'assetId'])->name('admin.dam.collections.members.preview');
        Route::get('{id}/members/{assetId}/download', 'memberDownload')->whereNumber(['id', 'assetId'])->name('admin.dam.collections.members.download')->middleware(SignalDownloadStart::class);
        Route::get('{id}/members/{assetId}/proxy', 'memberProxy')->whereNumber(['id', 'assetId'])->name('admin.dam.collections.members.proxy');
    });

    // P5c (D16): near-duplicate images, reviewed in pairs.
    Route::controller(SimilarImageController::class)->prefix('similar-images')->group(function () {
        Route::get('', 'index')->name('admin.dam.similar.index');
        Route::post('dismiss', 'dismiss')->name('admin.dam.similar.dismiss');
    });

    // P5c (D8): open AI tag suggestions across the Library, accepted or dismissed in bulk.
    Route::controller(AiTagReviewController::class)->prefix('ai-review')->group(function () {
        Route::get('', 'index')->name('admin.dam.ai-review.index');
        Route::post('bulk', 'bulk')->name('admin.dam.ai-review.bulk');
    });

    // P2 S4: the 30-day trash.
    Route::controller(TrashController::class)->prefix('trash')->group(function () {
        Route::get('', 'index')->name('admin.dam.trash.index');
        Route::get('list', 'list')->name('admin.dam.trash.list');
        Route::post('{id}/restore', 'restore')->name('admin.dam.trash.restore')->where('id', '[0-9]+');
        Route::delete('{id}', 'destroy')->name('admin.dam.trash.destroy')->where('id', '[0-9]+');
    });

    Route::controller(TagManagementController::class)->prefix('tags')->group(function () {
        Route::get('', 'index')->name('admin.dam.tags.index');
        Route::get('/list', 'list')->name('admin.dam.tags.list');
        Route::post('', 'store')->name('admin.dam.tags.store');
        Route::post('/mass-delete', 'massDestroy')->name('admin.dam.tags.mass_delete');
        Route::put('/{id}', 'update')->name('admin.dam.tags.update')->where('id', '[0-9]+');
        Route::delete('/{id}', 'destroy')->name('admin.dam.tags.destroy')->where('id', '[0-9]+');
    });

    Route::controller(DirectoryController::class)->prefix('directory')->group(function () {
        Route::get('', 'index')->name('admin.dam.directory.index');
        Route::get('/search', 'search')->name('admin.dam.directory.search');
        Route::get('/children-directory/{id}', 'childrenDirectory')->name('admin.dam.directory.children');
        Route::post('/asset-counts', 'assetCounts')->name('admin.dam.directory.asset_counts');
        Route::get('/path/{id}', 'directoryPath')->name('admin.dam.directory.path');
        Route::get('/directory-assets/{id}', 'directoryAssets')->name('admin.dam.directory.assets');
        Route::post('/store', 'store')->name('admin.dam.directory.store');
        Route::post('/update', 'update')->name('admin.dam.directory.update');
        Route::delete('/destroy/{id}', 'destroy')->name('admin.dam.directory.destroy');
        Route::post('/copy', 'copy')->name('admin.dam.directory.copy');
        Route::get('zip-download/{id}', 'downloadArchive')->name('admin.dam.directory.zip_download')->middleware(SignalDownloadStart::class);
        Route::post('/copy-structure', 'copyStructure')->name('admin.dam.directory.copy_structure');
        Route::post('/moved', 'moved')->name('admin.dam.directory.moved');
        Route::post('/create-structure', 'createStructure')->name('admin.dam.directory.create_structure');
        Route::post('/paths', 'ancestorPaths')->name('admin.dam.directory.paths');
        Route::get('/{id}/descendants', 'descendants')->name('admin.dam.directory.descendants');
        Route::post('/mass-delete', 'massDestroy')->name('admin.dam.directory.mass_destroy');
    });

    Route::controller(ActionRequestController::class)->prefix('action-request')->group(function () {
        Route::get('/status/{eventType}', 'fetchStatus')->name('admin.dam.action_request.status');
    });

    Route::controller(AssetPickerController::class)->prefix('picker')->group(function () {
        Route::get('', 'index')->name('admin.dam.asset_picker.index');

        Route::get('/get', 'fetchAssets')->name('admin.dam.asset_picker.get_assets');
    });

    Route::controller(CaptureConfigurationController::class)->group(function () {
        Route::get('configuration/capture', 'index')->name('admin.dam.configuration.capture');
        Route::put('configuration/detail-layouts', 'layouts')->name('admin.dam.configuration.layouts');
        Route::post('configuration/capture-profiles', 'saveProfile')->name('admin.dam.configuration.profiles.store');
        Route::put('configuration/capture-profiles/{profile}', 'saveProfile')->whereNumber('profile')->name('admin.dam.configuration.profiles.update');
        Route::post('configuration/capture-preview', 'preview')->name('admin.dam.configuration.capture.preview');
        Route::post('configuration/capture-apply', 'apply')->name('admin.dam.configuration.capture.apply');
        Route::get('assets/{asset}/capture', 'showCapture')->whereNumber('asset')->name('admin.dam.assets.capture.show');
        Route::put('assets/{asset}/capture', 'updateCapture')->whereNumber('asset')->name('admin.dam.assets.capture.update');
        Route::delete('assets/{asset}/capture', 'ungroup')->whereNumber('asset')->name('admin.dam.assets.capture.destroy');
    });

    // P4a (D9): custom metadata field definitions, under the Library settings.
    Route::controller(MetadataFieldController::class)
        ->prefix('configuration/metadata-fields')
        ->name('admin.dam.configuration.fields.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('/order', 'reorder')->name('order');
            Route::put('/{field}', 'update')->whereNumber('field')->name('update');
            Route::delete('/{field}', 'destroy')->whereNumber('field')->name('destroy');
            Route::post('/{field}/restore', 'restore')->whereNumber('field')->name('restore');
        });

    // P5b (D18): embedded-metadata mappings, under the Library settings.
    Route::controller(MetadataMappingController::class)
        ->prefix('configuration/metadata-mappings')
        ->name('admin.dam.configuration.mappings.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/keys', 'keys')->name('keys');
            Route::post('/dry-run', 'dryRun')->name('dry_run');
            Route::put('/{mapping}', 'update')->whereNumber('mapping')->name('update');
            Route::delete('/{mapping}', 'destroy')->whereNumber('mapping')->name('destroy');
            Route::post('/{mapping}/run', 'run')->whereNumber('mapping')->name('run');
        });

    // P5b (D19): automation rules, their dry run, run-now and run log, under the Library settings.
    Route::controller(AutomationRuleController::class)
        ->prefix('configuration/automation-rules')
        ->name('admin.dam.configuration.rules.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('/dry-run', 'dryRun')->name('dry_run');
            Route::put('/{rule}', 'update')->whereNumber('rule')->name('update');
            Route::delete('/{rule}', 'destroy')->whereNumber('rule')->name('destroy');
            Route::post('/{rule}/run', 'run')->whereNumber('rule')->name('run');
            Route::get('/{rule}/runs', 'runs')->whereNumber('rule')->name('runs');
            Route::get('/{rule}/runs/{run}', 'showRun')->whereNumber(['rule', 'run'])->name('runs.show');
        });

    // P5c (D8): the AI vocabulary, under the Library settings.
    Route::controller(AiVocabularyController::class)
        ->prefix('configuration/ai-vocabulary')
        ->name('admin.dam.configuration.ai-vocabulary.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('/{term}', 'update')->where('term', '[a-z0-9][a-z0-9-]{0,63}')->name('update');
        });

    Route::controller(ConfigurationController::class)
        ->prefix('configuration')
        ->name('admin.dam.configuration.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'update')->name('update');
        });

});
