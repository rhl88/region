<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

/**
 * 一键数据更新功能测试
 *
 * 覆盖「一键数据更新 / 一键导入」串联功能的页面渲染与后端接口契约：
 * 1. 管理页渲染两个主按钮（均带悬停下拉菜单）、四步骤标签、统一进度面板
 * 2. 一键导入（本地数据链路）与一键数据更新（在线链路）的入口分流
 * 3. 串联流程依赖的全部后端接口路由存在且响应结构正确
 * 4. 未登录访问被 auth:admin 中间件拦截
 *
 * 说明：串联调度由前端 JS 复用现有接口串行完成（零后端改动），
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
            // 一键导入按钮（本地数据链路：三级区划 → 街道 → 地理位置，与在线更新链路分流）
            ->assertSee('oneKeyImportBtn', false)
            ->assertSee('一键导入', false)
            ->assertSee('triggerOneKeyRun', false)
            ->assertSee("startOneKeyUpdate('一键导入', 'local')", false)
            ->assertSee("startOneKeyUpdate('一键数据更新', 'online')", false)
            // 本地模式第三阶段使用 Excel 全量导入接口
            ->assertSee('import-geo-data', false)
            // 空数据引导：首次安装指向推荐链路（在线同步官方数据 / 导入快照包），
            // 「一键导入」已收纳进仅开发模式可见的旧版面板，不再作为空数据提示入口
            ->assertSee('请点击「在线同步官方数据」从版本服务器同步', false)
            ->assertSee('或点击「导入快照包」导入其他站点分享的快照包', false)
            // 悬停下拉菜单（两个主按钮各自收纳单项入口；更新菜单5项含港澳台/坐标，导入菜单3项为本地链路阶段）
            ->assertSee('one-key-menu', false)
            ->assertSee('one-key-import-menu', false)
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

    /**
     * 工具栏收纳：旧的四个功能按钮（一键导入 / 一键数据更新 / 清除缓存 / 更新数据）
     * 收进「旧版数据更新」下拉按钮，点击展开；「在线同步官方数据」加「推荐」标识。
     * 收纳后各功能的 id / data-btn / 二级悬停子菜单 / 串联 JS 入口均须保留（既有断言不回归）。
     */
    public function test_管理页工具栏收纳为旧版数据更新下拉并标识推荐(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            // 一级「旧版数据更新」下拉按钮 + 面板
            ->assertSee('legacyUpdateBtn', false)
            ->assertSee('旧版数据更新', false)
            ->assertSee('legacyMenu', false)
            // 四个功能按钮收纳后仍保留其 id（供禁用互斥与事件绑定）
            ->assertSee('oneKeyImportBtn', false)
            ->assertSee('oneKeyUpdateBtn', false)
            ->assertSee('clearCacheBtn', false)
            ->assertSee('updateDataBtn', false)
            // 二级悬停子菜单容器保留（一键导入 / 一键数据更新各自的单项入口）
            ->assertSee('one-key-import-menu', false)
            ->assertSee('data-btn="importBtn"', false)
            ->assertSee('data-btn="importHmtBtn"', false)
            // 「在线同步官方数据」推荐标识
            ->assertSee('syncRegionDataBtn', false)
            ->assertSee('recommend-badge', false)
            ->assertSee('推荐', false);
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
