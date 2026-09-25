<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\FolderController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/activity', [ActivityController::class, 'index']);

    Route::get('/brand', [BrandController::class, 'show']);
    Route::post('/brand', [BrandController::class, 'store']);
    Route::patch('/brand', [BrandController::class, 'update']);
    Route::post('/brand/logo', [BrandController::class, 'uploadLogo']);

    Route::get('/folders', [FolderController::class, 'index']);
    Route::post('/folders', [FolderController::class, 'store']);
    Route::patch('/folders/{id}', [FolderController::class, 'update']);
    Route::delete('/folders/{id}', [FolderController::class, 'destroy']);
    Route::post('/folders/{id}/restore', [FolderController::class, 'restore']);
    Route::delete('/folders/{id}/force', [FolderController::class, 'forceDestroy']);

    Route::get('/assets', [AssetController::class, 'index']);
    Route::post('/assets', [AssetController::class, 'store']);
    Route::post('/assets/suggest-tags', [AssetController::class, 'suggestTags']);
    Route::delete('/assets/trash', [AssetController::class, 'emptyTrash']);
    Route::match(['patch', 'post'], '/assets/{id}', [AssetController::class, 'update']);
    Route::delete('/assets/{id}', [AssetController::class, 'destroy']);
    Route::post('/assets/{id}/trash', [AssetController::class, 'trash']);
    Route::post('/assets/{id}/restore', [AssetController::class, 'restore']);
    Route::post('/assets/{id}/generate-tags', [AssetController::class, 'generateTags']);
});
