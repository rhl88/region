<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Models\RegionClosure;
use App\Apps\CmsproRegion\Services\RegionService;
use App\Apps\CmsproRegion\Services\RegionDataSyncService;
use App\Enums\Status;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * 村级（第五级）区划数据同步测试
 *
 * 覆盖村级数据纳入在线同步数据包后的完整链路：
 * 1. 五级全量替换落库：12 位村级编码、parent_code 挂接 9 位街道编码、level=5
 * 2. 闭包表流式重建：村级节点含自身与四级祖先共 5 条闭包记录
 * 3. 统计与查询：stats 含 village、级联树支持五级、坐标候选支持村级筛选
 */
class RegionVillageSyncTest extends RegionTestCase
{
    /**
     * 五级契约样本：省→市→区→街道→村（12 位村级编码，parent_code 为 9 位街道编码）
     */
    private function sampleFiveLevelRegions(): array
    {
        return [
            ['code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_code' => '0', 'status' => 1, 'lon' => 116.4, 'lat' => 39.9],
            ['code' => '110100', 'name' => '市辖区', 'level' => 2, 'parent_code' => '110000', 'status' => 1, 'lon' => 116.6, 'lat' => 40.1],
            ['code' => '110101', 'name' => '东城区', 'level' => 3, 'parent_code' => '110100', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101001', 'name' => '东华门街道', 'level' => 4, 'parent_code' => '110101', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101001001', 'name' => '多福巷社区居委会', 'level' => 5, 'parent_code' => '110101001', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101001002', 'name' => '银闸社区居委会', 'level' => 5, 'parent_code' => '110101001', 'status' => 1, 'lon' => null, 'lat' => null],
        ];
    }

    /**
     * 构造远端五级发布包（regions.json + meta.json 打 ZIP）
     *
     * @return array{content:string, checksum:string}
     */
    private function buildVillagePackage(array $regions): array
    {
        $meta = json_encode([
            'version' => '20261107001',
            'count' => count($regions),
            'stats' => ['province' => 1, 'city' => 1, 'district' => 1, 'street' => 1, 'village' => 2],
            'generated_at' => date('Y-m-d H:i:s'),
            'format' => 'code,name,level,parent_code,status,lon,lat',
        ], JSON_UNESCAPED_UNICODE);

        $zipPath = sys_get_temp_dir() . '/cmspro-village-pkg-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('regions.json', (string) json_encode($regions, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('meta.json', (string) $meta);
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        $checksum = (string) hash_file('sha256', $zipPath);
        unlink($zipPath);

        return ['content' => $content, 'checksum' => $checksum];
    }

    /**
     * 模拟版本服务器发布五级数据包
     */
    private function fakeVersionServer(array $package): void
    {
        Http::fake([
            '*/api/versionsvr/region-data/latest' => Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'version' => '20261107001',
                    'region_count' => 6,
                    'stats' => ['province' => 1, 'city' => 1, 'district' => 1, 'street' => 1, 'village' => 2],
                    'changelog' => '新增村级数据',
                    'file_size' => strlen($package['content']),
                    'checksum' => $package['checksum'],
                    'download_url' => '/api/versionsvr/region-data/download/20261107001',
                    'release_time' => '2026-11-07 12:00:00',
                ],
            ]),
            '*/api/versionsvr/region-data/download/*' => Http::response(
                $package['content'], 200, ['Content-Type' => 'application/zip']
            ),
        ]);
    }

    /**
     * 预置同步配置组（模拟已升级站点）
     */
    private function seedSyncConfig(): void
    {
        $group = ConfigGroup::create([
            'name' => '数据同步设置',
            'code' => 'app_cmspro_region_sync_settings',
            'app_id' => 'cmspro.region',
            'sort' => 0,
            'status' => Status::ENABLED,
        ]);

        ConfigItem::create([
            'group_id' => $group->id,
            'name' => '已同步数据版本',
            'code' => 'app_cmspro_region_data_sync_version',
            'value' => '',
            'type' => 'text',
            'tips' => '最近一次在线同步的官方区划数据版本号（系统自动维护，请勿手工修改）',
            'sort' => 0,
            'status' => Status::ENABLED,
        ]);
    }

    public function test_五级同步_村级数据落库与层级挂接(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $token = 'village_test_' . uniqid();
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['progress_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.started', true);

        // 同步接口已改为后台执行，终态从 sync-progress 轮询获取（测试环境同步执行）
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'done');
        $this->assertSame(6, Region::count());

        // 村级编码（12 位）与层级
        $village = Region::where('code', '110101001001')->firstOrFail();
        $street = Region::where('code', '110101001')->firstOrFail();
        $this->assertSame(Region::LEVEL_VILLAGE, $village->level);
        $this->assertSame('多福巷社区居委会', $village->name);

        // 村级 parent_code → parent_id：挂接 9 位街道编码对应记录
        $this->assertSame($street->id, $village->parent_id);

        // 落库后统计含村级计数
        $this->assertSame(2, Region::where('level', Region::LEVEL_VILLAGE)->count());
    }

    public function test_五级同步_村级闭包记录为五条(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data')
            ->assertOk()->assertJson(['code' => 0]);

        $village = Region::where('code', '110101001001')->firstOrFail();

        // 村级节点闭包：省/市/区/街道四个祖先 + 自身 = 5 条
        $this->assertSame(
            5,
            RegionClosure::where('descendant', $village->id)->count(),
            '村级节点应含自身与四级祖先共 5 条闭包记录'
        );

        // 闭包深度 0-4 全覆盖（0=自身，4=省级祖先）
        $depths = RegionClosure::where('descendant', $village->id)
            ->orderBy('depth')
            ->pluck('depth')
            ->all();
        $this->assertSame([0, 1, 2, 3, 4], $depths);

        // 全表闭包记录：1+2+3+4+5+5 = 20 条
        $this->assertSame(20, RegionClosure::count());
    }

    public function test_五级同步_getStats含村级统计(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data')
            ->assertOk();

        $stats = app(RegionService::class)->getStats();

        $this->assertSame(1, $stats['province']);
        $this->assertSame(1, $stats['city']);
        $this->assertSame(1, $stats['district']);
        $this->assertSame(1, $stats['street']);
        $this->assertSame(2, $stats['village']);
        $this->assertSame(6, $stats['total']);
    }

    public function test_五级同步_级联树支持村级层级(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data')
            ->assertOk();

        $tree = app(RegionService::class)->getCascaderData(5);

        // 五级级联：北京市 → 市辖区 → 东城区 → 东华门街道 → 多福巷社区居委会
        $children = $tree[0]['children'][0]['children'][0]['children'][0]['children'] ?? [];
        $this->assertNotEmpty($children, '五级级联树应包含村级节点');
        $this->assertSame('110101001001', (string) $children[0]['value']);
        $this->assertSame('多福巷社区居委会', $children[0]['label']);
    }

    public function test_五级同步_坐标候选支持村级筛选(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data')
            ->assertOk();

        $service = app(RegionService::class);

        // 显式勾选村级（levels=[5]）：返回村级候选，地址含五级完整路径
        $result = $service->getCoordinateCandidates([5], true, '', 10);
        $this->assertSame(2, count($result['items']));
        $this->assertSame('110101001001', $result['items'][0]['code']);
        $this->assertSame(
            '北京市市辖区 东城区东华门街道多福巷社区居委会',
            $result['items'][0]['address'],
            '村级地址应按「省+市 空格 区+街道+村」规则组装'
        );

        // 默认层级（未显式勾选村级）返回 1-4 级候选，不含村级
        $default = $service->getCoordinateCandidates([], false, '', 100);
        $this->assertSame(4, $default['total'], '默认层级应为 1-4 级共 4 条候选');
        $this->assertSame(
            [],
            array_filter($default['items'], fn (array $item): bool => $item['level'] === Region::LEVEL_VILLAGE),
            '默认层级候选不应包含村级'
        );
    }

    public function test_五级同步_进度终态为done(): void
    {
        $this->seedSyncConfig();

        $package = $this->buildVillagePackage($this->sampleFiveLevelRegions());
        $this->fakeVersionServer($package);

        $token = 'village_progress_' . uniqid();
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['progress_token' => $token])
            ->assertOk();

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.percent', 100);
    }

    public function test_校验_村级编码超12位被拒绝(): void
    {
        $this->seedSyncConfig();

        // 预置本地旧数据，校验拒绝后必须零影响
        $old = Region::create(['code' => '999000', 'name' => '旧省级', 'level' => 1, 'parent_id' => 0, 'status' => 1]);

        $badRegions = array_merge($this->sampleFiveLevelRegions(), [[
            'code' => '1101010010013', 'name' => '超长编码村', 'level' => 5,
            'parent_code' => '110101001', 'status' => 1, 'lon' => null, 'lat' => null,
        ]]);
        $package = $this->buildVillagePackage($badRegions);
        $this->fakeVersionServer($package);

        $token = 'village_bad_code_' . uniqid();
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['progress_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.started', true);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'error');

        // 原数据零影响
        $this->assertSame(1, Region::count());
        $this->assertSame('999000', (string) Region::firstOrFail()->code);
        $this->assertSame(0, RegionClosure::where('descendant', $old->id)->where('depth', 0)->count() ? 1 : 0);
    }

    public function test_校验_村级孤儿引用被拒绝(): void
    {
        $this->seedSyncConfig();

        Region::create(['code' => '999000', 'name' => '旧省级', 'level' => 1, 'parent_id' => 0, 'status' => 1]);

        // 孤儿引用：村级挂接不存在的街道编码
        $badRegions = [
            ['code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_code' => '0', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101999001', 'name' => '孤儿村', 'level' => 5, 'parent_code' => '110101999', 'status' => 1, 'lon' => null, 'lat' => null],
        ];
        $package = $this->buildVillagePackage($badRegions);
        $this->fakeVersionServer($package);

        $token = 'village_orphan_' . uniqid();
        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['progress_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.started', true);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'error');

        // 原数据零影响：旧记录仍在，村级未写入
        $this->assertSame(1, Region::count());
        $this->assertSame(0, Region::where('level', 5)->count());
    }

    /**
     * 管理页渲染村级统计卡片与层级筛选（视图层适配回归）
     */
    public function test_管理页渲染村级统计与筛选(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('statVillage', false)
            ->assertSee('村/社区', false);
    }

    /**
     * 同步服务的契约校验层：validateRegions 直接返回五级 stats（供发布端联调对照）
     */
    public function test_校验_validateRegions返回五级stats(): void
    {
        $service = app(RegionDataSyncService::class);

        $method = new \ReflectionMethod($service, 'validateRegions');
        $method->setAccessible(true);
        $result = $method->invoke($service, $this->sampleFiveLevelRegions());

        $this->assertSame(6, $result['count']);
        $this->assertSame(
            ['province' => 1, 'city' => 1, 'district' => 1, 'street' => 1, 'village' => 2],
            $result['stats']
        );
    }
}
