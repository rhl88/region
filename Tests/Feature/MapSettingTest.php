<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Models\AdminUser;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;

/**
 * 「应用设置」地图配置测试
 *
 * 覆盖：来源/厂商白名单校验、三家官方 SDK 地址、生效配置解析（none/own/maps）、
 *       配置组缺失时的写入保护、密钥加密落库、设置页与保存接口的权限与入参校验。
 */
class MapSettingTest extends RegionTestCase
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
    private function registerConfigGroup(): ConfigGroup
    {
        return ConfigGroup::create([
            'name'   => '地图设置',
            'code'   => MapSettingService::CONFIG_GROUP_CODE,
            'app_id' => 'cmspro.region',
            'sort'   => 0,
            'status' => 1,
        ]);
    }

    /** 写入独立配置（source=own）并指定厂商密钥 */
    private function saveOwnConfig(string $provider, array $keys = []): void
    {
        $this->service()->set(array_merge([
            'map_source'   => MapSettingService::SOURCE_OWN,
            'map_provider' => $provider,
        ], $keys));
    }

    public function test_配置来源与厂商白名单校验(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isValidSource('none'));
        $this->assertTrue($service->isValidSource('own'));
        $this->assertTrue($service->isValidSource('maps'));
        $this->assertFalse($service->isValidSource('google'));
        $this->assertFalse($service->isValidSource(''));

        $this->assertTrue($service->isValidProvider('tencent'));
        $this->assertTrue($service->isValidProvider('amap'));
        $this->assertTrue($service->isValidProvider('baidu'));
        $this->assertFalse($service->isValidProvider('google'));
    }

    public function test_三家厂商返回官方_SDK_地址(): void
    {
        $service = $this->service();

        $this->assertSame(
            'https://map.qq.com/api/gljs?v=1.exp&key=QQKEY',
            $service->sdkUrl('tencent', 'QQKEY')
        );
        $this->assertSame(
            'https://webapi.amap.com/maps?v=2.0&key=AMAPKEY',
            $service->sdkUrl('amap', 'AMAPKEY')
        );

        // 百度 GL 必须带 callback，否则官方引导脚本用 document.write 导致 BMapGL 不存在
        $baidu = $service->sdkUrl('baidu', 'BAIDUAK');
        $this->assertStringStartsWith('https://api.map.baidu.com/api?', $baidu);
        $this->assertStringContainsString('type=webgl', $baidu);
        $this->assertStringContainsString('ak=BAIDUAK', $baidu);
        $this->assertStringContainsString('callback=__cmsproRegionBaiduReady', $baidu);

        $this->assertSame('', $service->sdkUrl('google', 'X'));
    }

    public function test_坐标系按厂商区分(): void
    {
        $service = $this->service();

        $this->assertSame('bd09', $service->coordinateSystem('baidu'));
        $this->assertSame('gcj02', $service->coordinateSystem('amap'));
        $this->assertSame('gcj02', $service->coordinateSystem('tencent'));
    }

    public function test_未启用厂商地图时解析为未配置(): void
    {
        $effective = $this->service()->resolveEffective();

        $this->assertFalse($effective['configured']);
        $this->assertSame(MapSettingService::SOURCE_NONE, $effective['source']);
        $this->assertSame('', $effective['sdk_url']);
        $this->assertSame('gcj02', $effective['coordinate_system']);
        $this->assertNotSame('', $effective['message']);
    }

    public function test_独立配置未填密钥时回退瓦片底图(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('baidu');

        $effective = $this->service()->resolveEffective();

        $this->assertFalse($effective['configured']);
        $this->assertSame('baidu', $effective['provider']);
        $this->assertSame('', $effective['key']);
        $this->assertStringContainsString('回退', $effective['message']);
    }

    public function test_独立配置百度密钥后解析出生效配置(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('baidu', ['baidu_js_ak' => 'BAIDUAK']);

        $effective = $this->service()->resolveEffective();

        $this->assertTrue($effective['configured']);
        $this->assertSame(MapSettingService::SOURCE_OWN, $effective['source']);
        $this->assertSame('baidu', $effective['provider']);
        $this->assertSame('BAIDUAK', $effective['key']);
        $this->assertSame('bd09', $effective['coordinate_system']);
        $this->assertStringContainsString('ak=BAIDUAK', $effective['sdk_url']);
        $this->assertSame([], $effective['security_config']);
        $this->assertSame('', $effective['message']);
    }

    public function test_独立配置高德密钥后输出安全密钥注入配置(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('amap', ['amap_js_key' => 'AMAPKEY', 'amap_security_code' => 'SCODE']);

        $effective = $this->service()->resolveEffective();

        $this->assertTrue($effective['configured']);
        $this->assertSame('amap', $effective['provider']);
        $this->assertSame('gcj02', $effective['coordinate_system']);
        // 高德 JS API 2.0 强制要求 securityJsCode，须在 SDK 加载前注入
        $this->assertSame(
            ['_AMapSecurityConfig' => ['securityJsCode' => 'SCODE']],
            $effective['security_config']
        );
    }

    public function test_非法厂商不产生生效配置(): void
    {
        $this->registerConfigGroup();
        // 服务层只负责存储，厂商合法性由控制器与解析逻辑双重把关
        $this->service()->set(['map_source' => 'own', 'map_provider' => 'google']);

        $effective = $this->service()->resolveEffective();

        $this->assertFalse($effective['configured']);
        $this->assertSame('', $effective['provider']);
        $this->assertStringContainsString('不合法', $effective['message']);
    }

    public function test_关联地图应用不可用时回退瓦片底图(): void
    {
        $this->registerConfigGroup();
        $this->service()->set(['map_source' => MapSettingService::SOURCE_MAPS]);

        $effective = $this->service()->resolveEffective();

        $this->assertFalse($effective['configured']);
        $this->assertSame(MapSettingService::SOURCE_MAPS, $effective['source']);

        $status = $this->service()->mapsAppStatus();
        $this->assertSame($this->service()->mapsAppAvailable(), $status['installed']);
        if (! $status['installed']) {
            $this->assertStringContainsString('「地图」应用', $effective['message']);
        }
    }

    public function test_配置组未注册时保存抛出异常(): void
    {
        $this->assertFalse($this->service()->configGroupReady());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('地图配置组尚未注册');

        $this->service()->set(['map_source' => 'own']);
    }

    public function test_配置组注册后设置页可保存(): void
    {
        $this->registerConfigGroup();

        $this->assertTrue($this->service()->configGroupReady());
    }

    public function test_密钥加密落库且可明文读回(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('tencent', ['tencent_js_key' => 'QQKEY']);

        $stored = ConfigItem::where('code', 'app_cmspro_region_tencent_js_key')->value('value');
        $this->assertNotNull($stored);
        $this->assertNotSame('QQKEY', $stored);
        $this->assertSame('QQKEY', $this->service()->get('tencent_js_key'));
        // 非敏感项保持明文，便于系统配置页直接查看
        $this->assertSame('own', ConfigItem::where('code', 'app_cmspro_region_map_source')->value('value'));
    }

    public function test_保存忽略白名单外字段(): void
    {
        $group = $this->registerConfigGroup();
        $this->service()->set([
            'map_source' => 'none',
            'evil_code'  => 'x',
            'password'   => 'y',
        ]);

        $this->assertSame(1, ConfigItem::where('group_id', $group->id)->count());
        $this->assertSame('none', $this->service()->get('map_source'));
    }

    public function test_重复保存覆盖同一配置项不产生重复记录(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('baidu', ['baidu_js_ak' => 'AK1']);
        $this->saveOwnConfig('amap', ['baidu_js_ak' => 'AK2']);

        $this->assertSame(1, ConfigItem::where('code', 'app_cmspro_region_baidu_js_ak')->count());
        $this->assertSame('AK2', $this->service()->get('baidu_js_ak'));
        $this->assertSame('amap', $this->service()->get('map_provider'));
    }

    public function test_清空密钥即删除该配置(): void
    {
        $this->registerConfigGroup();
        $this->saveOwnConfig('baidu', ['baidu_js_ak' => 'AK1']);
        $this->saveOwnConfig('baidu', ['baidu_js_ak' => '']);

        $this->assertSame('', $this->service()->get('baidu_js_ak'));
        $this->assertFalse($this->service()->resolveEffective()['configured']);
    }

    public function test_设置页超管可访问(): void
    {
        $this->registerConfigGroup();

        $this->asAdmin()
            ->get('/admin/cmspro/region/setting')
            ->assertOk()
            ->assertSee('应用设置')
            ->assertSee('地图配置来源');
    }

    public function test_设置页配置组缺失时提示升级应用(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region/setting')
            ->assertOk()
            ->assertSee('地图配置组尚未注册');
    }

    /**
     * 配置组缺失时保存按钮仍须可点击
     *
     * 按钮一旦渲染 disabled，浏览器不触发 click，Layui 的 submit 事件也不会执行，
     * 用户点击「保存设置」将既无提示也无请求；原因改由保存接口返回后前端弹出。
     */
    public function test_设置页配置组缺失时保存按钮仍可点击(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region/setting')
            ->assertOk()
            ->assertSee('lay-filter="save">', false)
            ->assertDontSee('lay-filter="save" disabled', false);
    }

    public function test_设置页无权限用户返回403(): void
    {
        $user = AdminUser::create([
            'username' => 'viewer',
            'password' => 'secret123',
            'name'     => '只读账号',
            'status'   => 1,
        ]);

        $this->actingAs($user, 'admin')
            ->get('/admin/cmspro/region/setting')
            ->assertForbidden();
    }

    public function test_保存接口拒绝非法配置来源与厂商(): void
    {
        $this->registerConfigGroup();

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', ['map_source' => 'xxx'])
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '不支持的地图配置来源');

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', [
                'map_source'   => 'own',
                'map_provider' => 'google',
            ])
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '不支持的地图厂商');
    }

    public function test_保存接口成功返回生效配置(): void
    {
        $this->registerConfigGroup();

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', [
                'map_source'   => 'own',
                'map_provider' => 'baidu',
                'baidu_js_ak'  => 'AK1',
                'evil_code'    => 'x',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.provider', 'baidu')
            ->assertJsonPath('data.coordinate_system', 'bd09');

        // 白名单外字段被忽略，不落库
        $this->assertSame(0, ConfigItem::where('code', 'app_cmspro_region_evil_code')->count());
    }

    public function test_配置组缺失时保存接口返回中文提示(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', ['map_source' => 'own'])
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '地图配置组尚未注册，请先在「应用管理」中升级本应用后再保存设置');
    }

    public function test_保存接口无权限用户被拒绝(): void
    {
        $user = AdminUser::create([
            'username' => 'viewer2',
            'password' => 'secret123',
            'name'     => '只读账号',
            'status'   => 1,
        ]);

        $this->actingAs($user, 'admin')
            ->postJson('/api/admin/cmspro/region/setting', ['map_source' => 'own'])
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '无权修改应用设置');
    }
}
