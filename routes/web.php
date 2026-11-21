<?php

use App\Conversion\ToolCatalog;
use App\Conversion\WorkspaceManager;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Route;

$toolSlugs = app(ToolCatalog::class)->all()->keys()->all();

Route::get('/', HomeController::class)->name('home');

Route::get('/unduh/{workspace}', DownloadController::class)
    ->where('workspace', WorkspaceManager::ID_PATTERN)
    ->name('download');

Route::get('/unduh/{workspace}/pratinjau', [DownloadController::class, 'preview'])
    ->where('workspace', WorkspaceManager::ID_PATTERN)
    ->name('download.preview');

Route::get('/unduh/{workspace}/berkas/{index}', [DownloadController::class, 'member'])
    ->where(['workspace' => WorkspaceManager::ID_PATTERN, 'index' => '[0-9]{1,4}'])
    ->name('download.member');

Route::post('/unduh/zip', [DownloadController::class, 'bundle'])
    ->middleware('throttle:conversions')
    ->name('download.bundle');

Route::get('/{tool}', [ToolController::class, 'show'])
    ->whereIn('tool', $toolSlugs)
    ->name('tools.show');

Route::post('/{tool}', [ToolController::class, 'convert'])
    ->whereIn('tool', $toolSlugs)
    ->middleware('throttle:conversions')
    ->name('tools.convert');
