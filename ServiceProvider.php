<?php

namespace App\Apps\CmsproRegion;

use App\Apps\CmsproRegion\Services\RegionService;
use App\Services\HookManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('app/Apps/CmsproRegion/Config/region.php'),
            'apps.cmspro.region'
        );

        // 注册 RegionService 为单例，供其他应用引用
        $this->app->singleton(RegionService::class);
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
