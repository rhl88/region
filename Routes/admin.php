<?php

use App\Apps\CmsproRegion\Controllers\Admin\RegionController;
use App\Apps\CmsproRegion\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RegionController::class, 'index']);
Route::get('/demo', [RegionController::class, 'demo']);
Route::get('/setting', [SettingController::class, 'index']);
