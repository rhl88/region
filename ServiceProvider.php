<?php

namespace App\Apps\CmsproRegion;

use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Apps\CmsproRegion\Services\RegionService;
use App\Services\HookManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /** 应用 ID，与 manifest.json 的 id 保持一致。 */
    public const APP_ID = 'cmspro.region';

    /** 配置键命名空间：config('apps.cmspro.region.*')。 */
    public const CONFIG_KEY = 'apps.cmspro.region';

    /** 配置项 code 前缀：config_items.code = app_cmspro_region_{name}（框架按 app_id 自动生成）。 */
    public const CONFIG_CODE_PREFIX = 'app_cmspro_region_';

    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('app/Apps/CmsproRegion/Config/region.php'),
            'apps.cmspro.region'
        );

        // 注册 RegionService 为单例，供其他应用引用
        $this->app->singleton(RegionService::class);

        // 地图配置服务：设置页读写 + 演示页取生效配置
        $this->app->singleton(MapSettingService::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->loadViews();
        $this->registerHooks();
    }

    protected function registerRoutes(): void
    {
        // 后台视图路由
        Route::prefix('admin/cmspro/region')
            ->namespace('App\Apps\CmsproRegion\Controllers\Admin')
            ->middleware(['web', 'auth:admin'])
            ->group(base_path('app/Apps/CmsproRegion/Routes/admin.php'));

        // 后台API路由
        Route::prefix('api/admin/cmspro/region')
            ->namespace('App\Apps\CmsproRegion\Controllers\Admin')
            ->middleware(['web', 'auth:admin'])
            ->group(base_path('app/Apps/CmsproRegion/Routes/admin_api.php'));
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            base_path('app/Apps/CmsproRegion/Views'),
            'cmsproregion'
        );
    }

    protected function registerHooks(): void
    {
        try {
            $hooks = $this->app->make(HookManager::class);
            $hooksInstance = new Hooks();
            $hooksInstance->register($hooks);
        } catch (\Throwable $e) {
            // HookManager 可能未注册（应用未启用时）
        }
    }
}
