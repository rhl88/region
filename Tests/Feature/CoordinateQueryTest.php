<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;

/**
 * 「更新坐标 → 单个更新」库中经纬度查询接口测试
 *
 * 覆盖：有坐标回显、无坐标返回 null、区划不存在、非开发模式拒绝。
 */
class CoordinateQueryTest extends RegionTestCase
{
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

    public function test_非开发模式拒绝查询(): void
    {
        config(['app.dev_mode' => false]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/coordinate?code=110000')
            ->assertOk()
            ->assertJsonPath('code', 1);
    }
}