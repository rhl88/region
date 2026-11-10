<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;

/**
 * 「更新坐标 → 单个更新」库中经纬度查询接口测试
 *
 * 覆盖：有坐标回显、无坐标返回 null、区划不存在、非开发模式也可查询，
 *       以及五级级联下拉（含村/社区）的视图渲染与村级子项数据源。
 */
class CoordinateQueryTest extends RegionTestCase
{
    /**
     * 造一条街道及其下两个村/社区（level=5）
     */
    private function seedStreetWithVillages(): void
    {
        $street = Region::create([
            'code' => '110101001', 'name' => '东华门街道', 'level' => 4,
            'parent_id' => 0, 'status' => 1, 'lon' => null, 'lat' => null,
        ]);

        foreach ([['110101001001', '多福巷社区居委会'], ['110101001002', '银闸社区居委会']] as $village) {
            Region::create([
                'code' => $village[0], 'name' => $village[1], 'level' => 5,
                'parent_id' => $street->id, 'status' => 1, 'lon' => null, 'lat' => null,
            ]);
        }
    }

    public function test_单个更新弹窗渲染村级下拉(): void
    {
        config(['app.dev_mode' => true]);

        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('var coordMaxDepth = 5;', false)
            ->assertSee('id="coordSel5"', false);
    }

    public function test_街道子级接口返回村级区划(): void
    {
        config(['app.dev_mode' => true]);
        $this->seedStreetWithVillages();

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/children?code=110101001')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.code', '110101001001')
            ->assertJsonPath('data.0.name', '多福巷社区居委会')
            ->assertJsonPath('data.0.level', 5)
            ->assertJsonPath('data.1.code', '110101001002');
    }

    public function test_村级区划可查询库中经纬度(): void
    {
        config(['app.dev_mode' => true]);
        $this->seedStreetWithVillages();

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=110101001001')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.name', '多福巷社区居委会')
            ->assertJsonPath('data.level', 5)
            ->assertJsonPath('data.lon', null)
            ->assertJsonPath('data.lat', null);
    }

    public function test_返回所选区划库中经纬度(): void
    {
        config(['app.dev_mode' => true]);
        Region::create([
            'code' => '110000', 'name' => '北京市', 'level' => 1,
            'parent_id' => 0, 'status' => 1, 'lon' => 116.4, 'lat' => 39.9,
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=110000')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.name', '北京市')
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.lon', 116.4)
            ->assertJsonPath('data.lat', 39.9);
    }

    public function test_无坐标区划返回空经纬度(): void
    {
        config(['app.dev_mode' => true]);
        Region::create([
            'code' => '120000', 'name' => '天津市', 'level' => 1,
            'parent_id' => 0, 'status' => 1, 'lon' => null, 'lat' => null,
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=120000')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.lon', null)
            ->assertJsonPath('data.lat', null);
    }

    public function test_区划不存在返回错误(): void
    {
        config(['app.dev_mode' => true]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=999999')
            ->assertOk()
            ->assertJsonPath('code', 1);
    }

    public function test_非开发模式也可查询经纬度(): void
    {
        config(['app.dev_mode' => false]);
        Region::create([
            'code' => '110000', 'name' => '北京市', 'level' => 1,
            'parent_id' => 0, 'status' => 1, 'lon' => 116.4, 'lat' => 39.9,
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=110000')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.lon', 116.4)
            ->assertJsonPath('data.lat', 39.9);
    }
}