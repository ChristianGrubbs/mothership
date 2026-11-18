<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\FeedbackAdminController;
use Mothership\DAM\Http\Controllers\FeedbackController;

/*
 * Feedback & bug reports, under /admin/dam/feedback. Every route answers 404
 * while dam.feedback.enabled is off; FeedbackController decides who may send, read and triage.
 */
Route::controller(FeedbackController::class)->prefix('feedback')->group(function (): void {
    Route::post('', 'store')->middleware('throttle:dam-feedback')->name('admin.dam.feedback.store');
    Route::get('mine', 'mine')->name('admin.dam.feedback.mine');
    Route::get('{report}', 'show')->whereNumber('report')->name('admin.dam.feedback.show');
    Route::get('{report}/screenshot', 'screenshot')->whereNumber('report')->name('admin.dam.feedback.screenshot');
    Route::patch('{report}', 'update')->whereNumber('report')->name('admin.dam.feedback.update');
    Route::post('{report}/notes', 'addNote')->whereNumber('report')->middleware('throttle:60,1')->name('admin.dam.feedback.notes.store');
});

// Settings → Feedback, the triage grid (FeedbackAdminController: FeedbackAccess::canManage() only).
Route::get('feedback', [FeedbackAdminController::class, 'index'])->name('admin.dam.feedback.index');
