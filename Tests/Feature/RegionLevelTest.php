<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Services\MapSettingService;
use App\Apps\CmsproRegion\Services\RegionLevelService;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;

/**
 * 区划级别设置测试（1.7.0 新增 region_max_level 配置项）
 *
 * 覆盖「应用设置 · 区划级别设置」的完整边界：
 * 1. 设置页渲染级别下拉与保存接口校验（含配置组缺失时自愈建组）
 * 2. 数据演示页按级别降级渲染选择器（五级全渲染 / 仅到区县 / 仅省市）
 * 3. 级别设置仅影响演示页，对外 API 返回与库中数据不受影响
 * 4. 已同步级别缺配置时回退到库中实际最大层级（老站点升级后不重复全量同步）
 */
class RegionLevelTest extends RegionTestCase
{
    private function service(): RegionLevelService
    {
        return $this->app->make(RegionLevelService::class);
    }

    /**
     * 注册 manifest 声明的配置组（生产环境由框架在安装/升级应用时写入）。
     *
     * @param string $code 配置组 code，为空表示地图设置组（保存接口会先写地图配置）
     */
    private function registerConfigGroup(string $code = ''): ConfigGroup
    {
        return ConfigGroup::create([
            'name'   => $code === '' ? '地图设置' : '区划设置',
            'code'   => $code === '' ? MapSettingService::CONFIG_GROUP_CODE : $code,
            'app_id' => 'cmspro.region',
            'sort'   => 0,
            'status' => 1,
        ]);
    }

    /** 预置省/市/区县/街道四级数据（演示页选择器渲染与对外接口校验所需）。 */
    private function seedFourLevels(): void
    {
        $province = Region::create([
            'code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_id' => 0, 'status' => 1,
        ]);
        $city = Region::create([
            'code' => '110100', 'name' => '市辖区', 'level' => 2, 'parent_id' => $province->id, 'status' => 1,
        ]);
        $district = Region::create([
            'code' => '110101', 'name' => '东城区', 'level' => 3, 'parent_id' => $city->id, 'status' => 1,
        ]);
        Region::create([
            'code' => '110101001', 'name' => '东华门街道', 'level' => 4, 'parent_id' => $district->id, 'status' => 1,
        ]);
    }

    public function test_设置页渲染区划级别设置面板且默认五级(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region/setting')
            ->assertOk()
            ->assertSee('区划级别设置')
            ->assertSee('选择器显示级别')
            ->assertSee('name="region_max_level"', false)
            // 未保存过配置时取 Config/region.php 默认值 5，对应下拉项被选中
            ->assertSee('value="5" selected', false);
    }

    public function test_保存接口写入区划显示级别(): void
    {
        $this->registerConfigGroup();
        $this->registerConfigGroup('app_cmspro_region_region_settings');

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', ['region_max_level' => '3'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.region_max_level', 3);

        $this->assertSame(
            '3',
            (string) ConfigItem::where('code', 'app_cmspro_region_region_max_level')->value('value')
        );
        $this->assertSame(3, $this->service()->maxLevel());
    }

    /**
     * 「区划设置」配置组为 1.7.0 新增，站点尚未升级时保存级别仍须成功（自愈建组），
     * 否则设置页会静默丢失用户输入。
     */
    public function test_区划设置配置组缺失时保存级别自愈建组(): void
    {
        $this->registerConfigGroup();
        $this->assertSame(0, ConfigGroup::where('code', 'app_cmspro_region_region_settings')->count());

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', ['region_max_level' => '4'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(1, ConfigGroup::where('code', 'app_cmspro_region_region_settings')->count());
        $this->assertSame(
            '4',
            (string) ConfigItem::where('code', 'app_cmspro_region_region_max_level')->value('value')
        );
    }

    public function test_保存接口拒绝非法区划显示级别(): void
    {
        $this->registerConfigGroup();
        $this->registerConfigGroup('app_cmspro_region_region_settings');

        foreach (['0', '6', 'abc'] as $invalid) {
            $this->asAdmin()
                ->postJson('/api/admin/cmspro/region/setting', ['region_max_level' => $invalid])
                ->assertOk()
                ->assertJsonPath('code', 1);
        }

        // 非法值一律不落库，已保存的级别保持不变
        $this->assertSame(
            0,
            ConfigItem::where('code', 'app_cmspro_region_region_max_level')->count()
        );
    }

    /**
     * 未提交 region_max_level 时不改动级别（兼容仅保存地图配置的调用）。
     */
    public function test_仅保存地图配置时不影响区划显示级别(): void
    {
        $this->registerConfigGroup();
        $this->registerConfigGroup('app_cmspro_region_region_settings');
        $this->service()->setMaxLevel(4);

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/setting', ['map_source' => 'none'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.region_max_level', 4);

        $this->assertSame(4, $this->service()->maxLevel());
    }

    public function test_演示页级别为五级时渲染全部下拉(): void
    {
        $this->seedFourLevels();
        $this->registerConfigGroup('app_cmspro_region_region_settings');
        $this->service()->setMaxLevel(5);

        // 无村/社区数据时村级下拉本就不渲染，此处只校验区县与街道两级
        $this->asAdmin()
            ->get('/admin/cmspro/region/demo')
            ->assertOk()
            ->assertSee('id="districtSelect"', false)
            ->assertSee('id="streetSelect"', false)
            ->assertSee('var hasStreet = true;', false)
            ->assertDontSee('区划选择器仅渲染至该级别');
    }

    public function test_演示页级别为区县时不渲染街道下拉(): void
    {
        $this->seedFourLevels();
        $this->registerConfigGroup('app_cmspro_region_region_settings');
        $this->service()->setMaxLevel(3);

        $this->asAdmin()
            ->get('/admin/cmspro/region/demo')
            ->assertOk()
            ->assertSee('id="districtSelect"', false)
            ->assertDontSee('id="streetSelect"', false)
            ->assertSee('var hasStreet = false;', false)
            // 状态卡片仍按库中真实数据量，另以提示说明选择器被级别设置截断
            ->assertSee('区划选择器仅渲染至该级别')
            ->assertSee('省/市/区县（3级）');
    }

    /**
     * 级别低于 3（区县）时选择器降级渲染：只保留省 / 市两级下拉。
     */
    public function test_演示页级别低于区县时降级为省市两级(): void
    {
        $this->seedFourLevels();
        $this->registerConfigGroup('app_cmspro_region_region_settings');
        $this->service()->setMaxLevel(2);

        $this->asAdmin()
            ->get('/admin/cmspro/region/demo')
            ->assertOk()
            ->assertSee('id="provinceSelect"', false)
            ->assertSee('id="citySelect"', false)
            ->assertDontSee('id="districtSelect"', false)
            ->assertDontSee('id="streetSelect"', false)
            ->assertSee('区划选择器仅渲染至该级别')
            // 三级数据实际存在，不得误报「未导入」
            ->assertDontSee('三级区划数据未导入');
    }

    /**
     * 级别设置只约束演示页渲染，对外 API 返回契约零改动。
     */
    public function test_级别设置不影响对外接口返回(): void
    {
        $this->seedFourLevels();
        $this->registerConfigGroup('app_cmspro_region_region_settings');
        $this->service()->setMaxLevel(2);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/children?code=110101')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.code', '110101001');

        // 前台公开只读接口同样不受级别设置影响
        $this->getJson('/api/cmspro/region/children?code=110101')
            ->assertOk()
            ->assertJsonPath('data.0.code', '110101001');
    }

    /**
     * data_sync_level 为本功能新增配置项，老站点升级后尚无记录，
     * 须回退到库中实际最大层级，避免「本地已是最新」判断失效导致重复全量同步。
     */
    public function test_已同步级别无配置时回退库中最大层级(): void
    {
        $this->assertSame(0, $this->service()->syncedLevel());

        $this->seedFourLevels();
        $this->assertSame(4, $this->service()->syncedLevel());

        // 配置项存在时以配置为准，不再回查数据表
        $this->service()->saveSyncedLevel(3);
        $this->assertSame(3, $this->service()->syncedLevel());
        $this->assertSame(3, $this->service()->maxLevel(), '同步落库级别应联动区划显示级别');
    }

    public function test_设置非法级别时抛出中文异常(): void
    {
        $this->registerConfigGroup('app_cmspro_region_region_settings');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('区划显示级别必须为 1（省）至 5（村/社区）之间的整数');

        $this->service()->setMaxLevel(7);
    }
}
