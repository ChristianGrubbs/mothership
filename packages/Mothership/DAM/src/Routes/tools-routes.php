<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\ToolsController;

Route::get('', [ToolsController::class, 'index'])->name('admin.tools.index');
Route::get('{widget}', [ToolsController::class, 'show'])->name('admin.tools.show');
