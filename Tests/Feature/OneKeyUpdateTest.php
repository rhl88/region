<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

/**
 * 一键数据更新功能测试
 *
 * 覆盖「一键数据更新」串联功能的页面渲染与后端接口契约：
 * 1. 管理页渲染一键更新按钮、四步骤标签、统一进度面板
 * 2. 串联流程依赖的全部 6 个后端接口路由存在且响应结构正确
 * 3. 未登录访问被 auth:admin 中间件拦截
 *
 * 说明：四阶段调度由前端 JS 复用现有接口串行完成（方案 A，零后端改动），
 * 后端无新增接口，故本测试聚焦「接口契约不被破坏」的回归验证。
 */
class OneKeyUpdateTest extends RegionTestCase
{
    public function test_管理页渲染一键数据更新按钮与进度面板(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('oneKeyUpdateBtn', false)
            ->assertSee('一键数据更新', false)
            ->assertSee('oneKeyProgress', false)
            ->assertSee('oneKeyImportProgress', false)
            ->assertSee('oneKeyImportLog', false)
            // 四个阶段步骤标签
            ->assertSee('oneKeyStep1', false)
            ->assertSee('oneKeyStep2', false)
            ->assertSee('oneKeyStep3', false)
            ->assertSee('oneKeyStep4', false)
            ->assertSee('三级区划', false)
            ->assertSee('港澳台补全', false)
            ->assertSee('街道数据', false)
            ->assertSee('最新坐标', false)
            // 串联调度核心 JS 存在
            ->assertSee('startOneKeyUpdate', false)
            ->assertSee('runOneKeyStep1', false)
            ->assertSee('runOneKeyStep4', false)
            // 一键导入按钮（与一键数据更新共用同一套串联链路）
            ->assertSee('oneKeyImportBtn', false)
            ->assertSee('一键导入', false)
            ->assertSee('triggerOneKeyRun', false)
            // 悬停下拉菜单（收纳五个单项导入入口）
            ->assertSee('one-key-menu', false)
            ->assertSee('data-btn="importBtn"', false)
            ->assertSee('data-btn="importHmtBtn"', false)
            // 串联调用的接口路径在前端代码中出现（阶段1 为逐省分步：list-json-provinces + import-by-province）
            ->assertSee('list-json-provinces', false)
            ->assertSee('import-by-province', false)
            ->assertSee('import-hmt-region', false)
            ->assertSee('street-districts', false)
            ->assertSee('import-street-by-district-from-mca', false)
            ->assertSee('geojson-provinces', false)
            ->assertSee('import-geo-data-from-geojson', false);
    }

    public function test_未登录访问管理页被拦截(): void
    {
        $this->get('/admin/cmspro/region')->assertRedirect();
    }

    public function test_串联接口一_三级区划导入接口存在且需登录(): void
    {
        // 未登录返回 401，证明路由已注册且受保护
        // 说明：登录后会真实调用民政部 API 全量导入（外部网络依赖，耗时 3 分钟+），
        // 不在功能测试中触发，业务逻辑由既有导入功能与线上实测覆盖
        $this->postJson('/api/admin/cmspro/region/import-from-mca')
            ->assertUnauthorized();
    }

    public function test_串联接口二_港澳台补全接口存在且需登录(): void
    {
        $this->postJson('/api/admin/cmspro/region/import-hmt-region')
            ->assertUnauthorized();
    }

    public function test_串联接口三_街道区县列表接口契约(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/street-districts')
            ->assertOk()
            ->assertJson(['code' => 1, 'message' => '请指定省份代码']);
    }

    public function test_串联接口四_按区县导入街道接口契约(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/import-street-by-district-from-mca')
            ->assertOk()
            ->assertJson(['code' => 1, 'message' => '请指定区县代码']);
    }

    public function test_串联接口五_可更新坐标省份列表(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/geojson-provinces')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonCount(0, 'data');
    }

    public function test_串联接口六_按省导入坐标接口契约(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/import-geo-data-from-geojson')
            ->assertOk()
            ->assertJson(['code' => 1, 'message' => '请指定省份代码']);
    }

    public function test_省份列表接口供街道阶段收集区县使用(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/provinces')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonCount(0, 'data');
    }

    public function test_统计接口供完成刷新使用(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/stats')
            ->assertOk()
            ->assertJson([
                'code' => 0,
                'data' => [
                    'province' => 0,
                    'city' => 0,
                    'district' => 0,
                    'street' => 0,
                    'total' => 0,
                ],
            ]);
    }
}
