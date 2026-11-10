<?php

use App\Apps\CmsproRegion\Controllers\Admin\RegionController;
use Illuminate\Support\Facades\Route;

// 前台只读区划接口（公开演示页使用，仅开放查询类接口，不含任何写操作）
Route::get('/provinces', [RegionController::class, 'provinces']);
Route::get('/children', [RegionController::class, 'children']);
Route::get('/path', [RegionController::class, 'path']);
Route::get('/list', [RegionController::class, 'list']);
