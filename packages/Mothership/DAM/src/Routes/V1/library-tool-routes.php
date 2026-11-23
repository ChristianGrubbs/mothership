<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\API\Library\LibraryToolController;
use Mothership\DAM\Mcp\LibraryTool;

/*
 * The `library_*` tools for the local `mothership` launcher (docs/api/mothership-mcp.md). One route per tool, so
 * api.scope checks each against the scope of its REST twin (Config/api-acl.php): save needs the POST assets upload
 * scope, fetch the download scope, and so on.
 */
foreach (LibraryTool::actions() as $action) {
    Route::post('library/tools/'.$action, [LibraryToolController::class, 'call'])
        ->defaults('tool', $action)
        ->name('admin.api.dam.library_tools.'.$action);
}
