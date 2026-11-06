<?php

use App\Apps\CmsproRegion\Controllers\Admin\RegionController;
use Illuminate\Support\Facades\Route;

// 快照导出（生产端：全量导出供上传版本服务器发布）
Route::get('/export-snapshot', [RegionController::class, 'exportSnapshot']);

// 在线同步官方数据（消费端：从版本服务器同步区划数据，全量替换落库）
Route::get('/sync-check-update', [RegionController::class, 'syncCheckUpdate']);
Route::post('/sync-region-data', [RegionController::class, 'syncRegionData']);

Route::get('/provinces', [RegionController::class, 'provinces']);
Route::get('/children', [RegionController::class, 'children']);
Route::get('/tree', [RegionController::class, 'tree']);
Route::get('/path', [RegionController::class, 'path']);
Route::post('/import', [RegionController::class, 'import']);
Route::post('/import-by-province', [RegionController::class, 'importByProvince']);
Route::get('/stats', [RegionController::class, 'stats']);
Route::get('/list', [RegionController::class, 'list']);
Route::post('/clear-cache', [RegionController::class, 'clearCache']);
Route::get('/street-provinces', [RegionController::class, 'streetProvinces']);
Route::post('/import-street', [RegionController::class, 'importStreet']);
Route::get('/street-districts', [RegionController::class, 'streetDistricts']);
Route::post('/import-street-by-district', [RegionController::class, 'importStreetByDistrict']);
Route::get('/download-districts', [RegionController::class, 'downloadDistricts']);
Route::post('/download-district-data', [RegionController::class, 'downloadDistrictData']);
Route::post('/download-list-json', [RegionController::class, 'downloadListJson']);
Route::get('/check-github', [RegionController::class, 'checkGithubConnection']);
Route::post('/clear-data-files', [RegionController::class, 'clearDataFiles']);
Route::post('/rebuild-closure', [RegionController::class, 'rebuildClosure']);

// 民政部API导入路由
Route::get('/check-mca', [RegionController::class, 'checkMcaConnection']);
Route::post('/import-from-mca', [RegionController::class, 'importFromMca']);
Route::post('/import-street-from-mca', [RegionController::class, 'importStreetFromMca']);
Route::post('/import-street-by-district-from-mca', [RegionController::class, 'importStreetByDistrictFromMca']);

// 民政部API差异化对比路由
Route::post('/diff-three-level-from-mca', [RegionController::class, 'diffThreeLevelFromMca']);
Route::post('/diff-street-from-mca', [RegionController::class, 'diffStreetFromMca']);

// 地理位置数据路由
Route::get('/geo-provinces', [RegionController::class, 'geoProvinces']);
Route::post('/import-geo-data-by-province', [RegionController::class, 'importGeoDataByProvince']);
Route::post('/import-geo-data', [RegionController::class, 'importGeoData']);
Route::get('/geo-stats', [RegionController::class, 'geoStats']);

// GeoJSON 在线坐标导入路由（geojson.cn）
Route::get('/geojson-provinces', [RegionController::class, 'geojsonProvinces']);
Route::post('/import-geo-data-from-geojson', [RegionController::class, 'importGeoDataFromGeojson']);

// 港澳台区划补全路由（阿里 DataV 源）
Route::post('/import-hmt-region', [RegionController::class, 'importHmtRegion']);

// list.json省份列表（用于数据库为空时的首次导入）
Route::get('/list-json-provinces', [RegionController::class, 'listJsonProvinces']);
