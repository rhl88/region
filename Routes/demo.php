<?php

use App\Apps\CmsproRegion\Controllers\Admin\RegionController;
use Illuminate\Support\Facades\Route;

// 前台数据演示页（公开访问，无需登录；页面内只读接口走 /api/cmspro/region）
// 文件名不使用框架保留的 home.php / front.php，避免与「绑定域名应用」的自动加载约定冲突
Route::get('/demo', [RegionController::class, 'demo'])->defaults('demoMode', 'public');
