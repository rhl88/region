<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;

/**
 * 前台公开访问（区划演示页 + 只读接口）
 */
class FrontDemoTest extends RegionTestCase
{
    public function test_前台演示页无需登录可访问并使用只读接口(): void
    {
        $response = $this->get('/cmspro/region/demo');

        $response->assertOk();
        $response->assertSee("var API_BASE = '/api/cmspro/region';", false);
        // 前台模式不展示后台导入指引（引导卡片整体隐藏，空态仅提示联系管理员）
        $response->assertDontSee('数据导入引导');
        $response->assertDontSee('前往在线同步官方数据');
        $response->assertDontSee('前往导入快照包');
    }

    public function test_后台演示页保持使用后台接口(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region/demo')
            ->assertOk()
            ->assertSee("var API_BASE = '/api/admin/cmspro/region';", false)
            // 无数据时给出推荐导入引导：在线同步官方数据 / 导入快照包（与数据管理页工具栏一致）
            ->assertSee('数据导入引导', false)
            ->assertSee('前往在线同步官方数据', false)
            ->assertSee('前往导入快照包', false)
            ->assertDontSee('前往一键导入');
    }

    public function test_前台只读接口无需登录可访问(): void
    {
        Region::create([
            'code' => '110000', 'name' => '北京市', 'level' => 1,
            'parent_id' => 0, 'status' => 1,
        ]);

        $this->getJson('/api/cmspro/region/provinces')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.code', '110000');

        $this->getJson('/api/cmspro/region/list?keyword=110000&limit=1')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.code', '110000');
    }

    public function test_前台接口未开放任何写操作(): void
    {
        $this->postJson('/api/cmspro/region/import')->assertNotFound();
        $this->postJson('/api/cmspro/region/sync-region-data')->assertNotFound();
    }

    public function test_后台接口未登录仍被拦截(): void
    {
        $this->getJson('/api/admin/cmspro/region/provinces')->assertUnauthorized();
    }

    public function test_官网前台菜单声明的地址可公开访问(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../manifest.json'), true);

        // home_menus 由框架在应用安装/升级时同步到 admin_menus（terminal_type=home）
        $this->assertIsArray($manifest['home_menus'] ?? null);

        foreach ($manifest['home_menus'] as $menu) {
            $this->assertNotEmpty($menu['code'] ?? '', 'home_menus 菜单项必须声明 code');
            $this->assertNotEmpty($menu['path'] ?? '', 'home_menus 菜单项必须声明 path');
            // 菜单地址必须公开可访问，否则官网导航点击后会 404 或被重定向到登录页
            $this->get($menu['path'])->assertOk();
        }
    }
}