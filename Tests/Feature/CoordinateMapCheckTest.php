<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Models\ConfigGroup;

/**
 * 数据管理页「更新坐标」前置检测测试
 *
 * 覆盖：非开发模式不渲染入口；开发模式下按「应用设置」的四种状态
 *       （配置组未注册 / 未启用地图 / 生效厂商非百度 / 未填密钥 / 已配置百度 AK）
 *       输出对应的缺失原因与百度 JSAPI 加载开关。
 */
class CoordinateMapCheckTest extends RegionTestCase
{
    private function service(): MapSettingService
    {
        return app(MapSettingService::class);
    }

    /**
     * 注册 manifest 声明的地图配置组
     *
     * 生产环境由框架在安装/升级应用时按 manifest.json 写入，测试环境需手动构造。
     */
    private function registerConfigGroup(): void
    {
        ConfigGroup::create([
            'name'   => '地图设置',
            'code'   => MapSettingService::CONFIG_GROUP_CODE,
            'app_id' => 'cmspro.region',
            'sort'   => 0,
            'status' => 1,
        ]);
    }

    /**
     * 断言数据管理页输出了指定的缺失原因
     *
     * 视图用 @json 输出，中文会被转义成 \uXXXX，故按同规则编码后比对页面源码。
     */
    private function assertCoordTip(string $expected): void
    {
        $encoded = json_encode($expected, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('var coordMapTip = ' . $encoded . ';', false);
    }

    public function test_非开发模式不渲染更新坐标入口(): void
    {
        config(['app.dev_mode' => false]);

        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertDontSee('id="updateCoordBtn"', false)
            ->assertSee('var coordMapTip = "";', false);
    }

    public function test_配置组未注册时提示升级应用(): void
    {
        config(['app.dev_mode' => true]);

        $this->assertCoordTip('地图配置组尚未注册，请先在「应用管理」将本应用升级到 1.6.0，再到「应用设置」填写百度地图 AK');
    }

    public function test_未启用地图时提示改为独立配置百度地图(): void
    {
        config(['app.dev_mode' => true]);
        $this->registerConfigGroup();

        $this->service()->set(['map_source' => MapSettingService::SOURCE_NONE]);

        $this->assertCoordTip('「应用设置」中地图配置来源为「不启用」，请改为「本应用独立配置」并选择百度地图、填写百度 JS AK');
    }

    public function test_生效厂商非百度时提示改用百度地图(): void
    {
        config(['app.dev_mode' => true]);
        $this->registerConfigGroup();

        $this->service()->set([
            'map_source'   => MapSettingService::SOURCE_OWN,
            'map_provider' => 'amap',
            'amap_js_key'  => 'test-amap-key',
        ]);

        $this->assertCoordTip('「应用设置」当前生效厂商为高德地图，而「更新坐标」依赖百度地图地址解析，请改用百度地图并填写百度 JS AK');
    }

    public function test_独立配置未填百度密钥时提示填写(): void
    {
        config(['app.dev_mode' => true]);
        $this->registerConfigGroup();

        $this->service()->set([
            'map_source'   => MapSettingService::SOURCE_OWN,
            'map_provider' => 'baidu',
        ]);

        $this->assertCoordTip('「应用设置」中尚未填写百度 JS AK（关联「地图」应用时也未配置百度浏览器端 AK），请先完成配置');
    }

    public function test_已配置百度密钥时加载JSAPI且无缺失提示(): void
    {
        config(['app.dev_mode' => true]);
        $this->registerConfigGroup();

        $this->service()->set([
            'map_source'   => MapSettingService::SOURCE_OWN,
            'map_provider' => 'baidu',
            'baidu_js_ak'  => 'test-baidu-ak',
        ]);

        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('var coordMapTip = "";', false)
            ->assertSee('id="updateCoordBtn"', false)
            ->assertSee('https://api.map.baidu.com/api?v=4.0&ak=test-baidu-ak', false);
    }
}
