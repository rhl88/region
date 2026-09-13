<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Models\AdminUser;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CmsproRegion 后台测试基类
 *
 * 测试环境（sqlite :memory:）中应用由 apps 表动态加载，数据库无记录时应用不注册，
 * 因此本基类手动完成三件事：
 * 1. 执行本应用迁移（app_cmspro_region_* 两张表 + geo 列）
 * 2. 种子超管角色与用户（super_admin / admin）
 * 3. 注册 ServiceProvider（加载 cmsproregion:: 视图命名空间与 admin 路由）
 */
abstract class RegionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        // 与 Install::runMigrations 同逻辑：按文件名顺序执行应用迁移
        $files = glob(__DIR__ . '/../../Migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }

        $this->seed(AdminRoleSeeder::class);
        $this->seed(AdminUserSeeder::class);

        // 测试库 apps 表无本应用记录，手动注册 Provider 模拟生产已安装状态
        $this->app->register(\App\Apps\CmsproRegion\ServiceProvider::class);
    }

    /**
     * 设置当前超管登录态（AdminUserSeeder 创建的 admin 用户）
     *
     * 返回 TestCase 自身，支持后续链式调用 get()/getJson() 等请求方法
     */
    protected function asAdmin(): static
    {
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $this->actingAs($admin, 'admin');

        return $this;
    }
}
