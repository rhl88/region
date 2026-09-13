<?php

use App\Apps\CmsproRegion\Controllers\Admin\RegionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RegionController::class, 'index']);
Route::get('/demo', [RegionController::class, 'demo']);
