<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Enums\Status;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Models\RegionClosure;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * 在线同步官方数据测试（阶段三：消费端）
 *
 * 覆盖「在线同步官方数据」的完整链路（Http::fake 模拟版本服务器）：
 * 1. 检查更新：远端版本信息解析、本地版本对比（need_update）
 * 2. 全量替换落库：下载 → SHA-256 校验 → 解包 → 契约校验 →
 *    单事务全删重插（分层 chunk 批量插入、parent_code→parent_id）→ 闭包表重建 → 清缓存 → 记版本
 * 3. 安全防护：校验和不符 / 契约非法（孤儿引用）时拒绝且原数据零影响（事务回滚）
 * 4. 版本控制：本地已是最新跳过下载、指定历史版本回退
 */
class RegionDataSyncTest extends RegionTestCase
{
    /** 远端包契约样本：四级 5 条（含 status=0 禁用与无坐标记录），parent_code 形式 */
    private function sampleRemoteRegions(): array
    {
        return [
            ['code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_code' => '0', 'status' => 1, 'lon' => 116.4, 'lat' => 39.9],
            ['code' => '120000', 'name' => '天津市', 'level' => 1, 'parent_code' => '0', 'status' => 0, 'lon' => null, 'lat' => null],
            ['code' => '110100', 'name' => '市辖区', 'level' => 2, 'parent_code' => '110000', 'status' => 1, 'lon' => 116.6, 'lat' => 40.1],
            ['code' => '110101', 'name' => '东城区', 'level' => 3, 'parent_code' => '110100', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101001', 'name' => '东华门街道', 'level' => 4, 'parent_code' => '110101', 'status' => 1, 'lon' => null, 'lat' => null],
        ];
    }

    /**
     * 构造远端发布包（regions.json + meta.json 打 ZIP）。
     *
     * @return array{content:string, checksum:string}
     */
    private function buildRemotePackage(array $regions, string $version): array
    {
        $meta = json_encode([
            'version' => $version,
            'count' => count($regions),
            'stats' => ['province' => 2, 'city' => 1, 'district' => 1, 'street' => 1],
            'generated_at' => date('Y-m-d H:i:s'),
            'format' => 'code,name,level,parent_code,status,lon,lat',
        ], JSON_UNESCAPED_UNICODE);

        $zipPath = sys_get_temp_dir() . '/cmspro-remote-pkg-' . uniqid() . '.zip';
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
     * 模拟版本服务器「最新已发布版本」与「下载」两个接口。
     */
    private function fakeVersionServer(array $package, string $version, array $overrides = []): void
    {
        $latestData = array_merge([
            'version' => $version,
            'region_count' => 5,
            'stats' => ['province' => 2, 'city' => 1, 'district' => 1, 'street' => 1],
            'changelog' => '例行更新',
            'file_size' => strlen($package['content']),
            'checksum' => $package['checksum'],
            'download_url' => '/api/versionsvr/region-data/download/' . $version,
            'release_time' => '2026-11-06 12:00:00',
        ], $overrides);

        Http::fake([
            '*/api/versionsvr/region-data/latest' => Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => $latestData,
            ]),
            '*/api/versionsvr/region-data/download/*' => Http::response(
                $package['content'], 200, ['Content-Type' => 'application/zip']
            ),
        ]);
    }

    /**
     * 创建同步配置组与已同步版本项（模拟已安装站点执行过「升级」后的配置落库状态）。
     */
    private function seedSyncConfig(string $version = ''): void
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
            'value' => $version,
            'type' => 'text',
            'tips' => '最近一次在线同步的官方区划数据版本号（系统自动维护，请勿手工修改）',
            'sort' => 0,
            'status' => Status::ENABLED,
        ]);
    }

    /**
     * 预置本地旧数据（同步前状态：2 条旧记录 + 1 条闭包记录）。
     */
    private function seedLocalOldData(): void
    {
        $old = Region::create([
            'code' => '999000', 'name' => '旧省级', 'level' => 1,
            'parent_id' => 0, 'status' => 1,
        ]);
        Region::create([
            'code' => '999001', 'name' => '旧市级', 'level' => 2,
            'parent_id' => $old->id, 'status' => 1,
        ]);
        RegionClosure::create(['ancestor' => $old->id, 'descendant' => $old->id, 'depth' => 0]);
    }

    public function test_未登录访问同步接口被拦截(): void
    {
        $this->getJson('/api/admin/cmspro/region/sync-check-update')->assertUnauthorized();
        $this->postJson('/api/admin/cmspro/region/sync-region-data')->assertUnauthorized();
    }

    public function test_检查更新_远端无发布版本时返回不可用(): void
    {
        Http::fake([
            '*/api/versionsvr/region-data/latest' => Http::response([
                'code' => 50006, 'message' => '暂无已发布版本', 'data' => null,
            ]),
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-check-update')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.need_update', false);
    }

    public function test_检查更新_远端有新版本需要更新(): void
    {
        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');
        $this->seedSyncConfig('');

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-check-update')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.need_update', true)
            ->assertJsonPath('data.version', '20261106001')
            ->assertJsonPath('data.region_count', 5)
            ->assertJsonPath('data.local_version', '');
    }

    public function test_检查更新_本地已是最新版本(): void
    {
        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');
        $this->seedSyncConfig('20261106001');

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-check-update')
            ->assertOk()
            ->assertJsonPath('data.need_update', false)
            ->assertJsonPath('data.local_version', '20261106001');
    }

    public function test_同步_全量替换落库成功(): void
    {
        $this->seedLocalOldData();
        $this->seedSyncConfig('');

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $response = $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['version' => null]);

        $response->assertOk()->assertJson(['code' => 0]);
        $this->assertTrue((bool) $response->json('data.synced'), '同步未执行');
        $this->assertSame('20261106001', (string) $response->json('data.version'));

        // 主表：全量替换（旧数据 999000/999001 消失，新数据 5 条按契约写入）
        $this->assertSame(0, Region::where('code', 'like', '999%')->count());
        $this->assertSame(5, Region::count());

        $beijing = Region::where('code', '110000')->firstOrFail();
        $tianjin = Region::where('code', '120000')->firstOrFail();
        $city = Region::where('code', '110100')->firstOrFail();
        $district = Region::where('code', '110101')->firstOrFail();
        $street = Region::where('code', '110101001')->firstOrFail();

        // parent_code → parent_id 转换正确（顶级为 0，子级指向父记录）
        $this->assertSame(0, $beijing->parent_id);
        $this->assertSame(0, $tianjin->parent_id);
        $this->assertSame($beijing->id, $city->parent_id);
        $this->assertSame($city->id, $district->parent_id);
        $this->assertSame($district->id, $street->parent_id);

        // status / lon / lat 契约字段保留
        $this->assertSame(0, $tianjin->status);
        $this->assertSame(1, $beijing->status);
        $this->assertSame(116.4, $beijing->lon);
        $this->assertNull($district->lon);

        // 闭包表重建：5 节点（1+1+2+3+4 = 11 条闭包记录）
        $this->assertSame(11, RegionClosure::count());
        $this->assertSame(
            4,
            RegionClosure::where('descendant', $street->id)->count(),
            '街道节点应含自身与三级祖先共 4 条闭包记录'
        );

        // 已同步版本写入配置项
        $this->assertSame(
            '20261106001',
            (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value')
        );
    }

    public function test_同步_校验和不符时拒绝且原数据零影响(): void
    {
        $this->seedLocalOldData();

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        // latest 声明一个错误的 checksum（模拟传输损坏/被篡改）
        $this->fakeVersionServer($package, '20261106001', ['checksum' => str_repeat('0', 64)]);

        $response = $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data');

        $response->assertOk()->assertJson(['code' => 1]);

        // 原数据零影响：旧记录仍在，新数据未写入
        $this->assertSame(2, Region::count());
        $this->assertSame(2, Region::where('code', 'like', '999%')->count());
        $this->assertSame(1, RegionClosure::count());
    }

    public function test_同步_数据包契约非法时拒绝且原数据零影响(): void
    {
        $this->seedLocalOldData();

        // 孤儿引用：110101 引用不存在的 110100
        $badRegions = [
            ['code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_code' => '0', 'status' => 1, 'lon' => null, 'lat' => null],
            ['code' => '110101', 'name' => '东城区', 'level' => 3, 'parent_code' => '110100', 'status' => 1, 'lon' => null, 'lat' => null],
        ];
        $package = $this->buildRemotePackage($badRegions, '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $response = $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data');

        $response->assertOk()->assertJson(['code' => 1]);

        $this->assertSame(2, Region::count());
        $this->assertSame(2, Region::where('code', 'like', '999%')->count());
    }

    public function test_同步_本地已是最新且未指定版本时跳过下载(): void
    {
        $this->seedSyncConfig('20261106001');

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $response = $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data');

        $response->assertOk()->assertJson(['code' => 0]);
        $this->assertFalse((bool) $response->json('data.synced'), '版本相同应跳过同步');

        // 未触发下载请求（fake 记录请求序列：只有 latest，没有 download）
        Http::assertSentCount(1);
    }

    public function test_同步_指定历史版本回退(): void
    {
        $this->seedLocalOldData();
        $this->seedSyncConfig('20261107001'); // 本地是更新版本，主动回退到旧版本

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $response = $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['version' => '20261106001']);

        $response->assertOk()->assertJson(['code' => 0]);
        $this->assertTrue((bool) $response->json('data.synced'));
        $this->assertSame(5, Region::count());

        // 版本记录回退为指定版本
        $this->assertSame(
            '20261106001',
            (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value')
        );
    }

    public function test_管理页渲染在线同步官方数据按钮(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('syncRegionDataBtn', false)
            ->assertSee('在线同步官方数据', false)
            ->assertSee('sync-check-update', false)
            ->assertSee('sync-region-data', false);
    }

    /**
     * 问题1回归：未预置配置组（未执行应用升级的旧站点）时，同步后版本号仍须自愈写入，
     * 否则 localVersion() 恒读空，前端「本地版本」一直显示「未同步」。
     */
    public function test_同步_配置组不存在时自愈建组并写入版本号(): void
    {
        $this->seedLocalOldData();
        // 故意不 seedSyncConfig：模拟站点从未对区划应用执行升级，配置组缺失

        $this->assertSame(0, ConfigGroup::where('code', 'app_cmspro_region_sync_settings')->count());

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data')
            ->assertOk()
            ->assertJson(['code' => 0]);

        // 配置组被自动创建，版本号落库
        $this->assertSame(1, ConfigGroup::where('code', 'app_cmspro_region_sync_settings')->count());
        $this->assertSame(
            '20261106001',
            (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value')
        );
    }

    /**
     * 问题2：同步进度接口——未知令牌返回 idle 态（前端轮询起始状态）。
     */
    public function test_同步进度接口_未知令牌返回idle(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=not_exist_token')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('data.state', 'idle')
            ->assertJsonPath('data.percent', 0);
    }

    /**
     * 问题2：传入进度令牌同步后，可轮询到终态进度（done/100），前端据此实时渲染进度条。
     */
    public function test_同步接口_传入进度令牌后可轮询到进度状态(): void
    {
        $this->seedLocalOldData();
        $this->seedSyncConfig('');

        $package = $this->buildRemotePackage($this->sampleRemoteRegions(), '20261106001');
        $this->fakeVersionServer($package, '20261106001');

        $token = 'sync_test_' . uniqid();

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/sync-region-data', ['progress_token' => $token])
            ->assertOk()
            ->assertJson(['code' => 0]);

        // 同步为同步请求，返回时进度已推进到终态；轮询应读到 done/100
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.percent', 100);
    }

    public function test_未登录访问进度接口被拦截(): void
    {
        $this->getJson('/api/admin/cmspro/region/sync-progress?progress_token=x')
            ->assertUnauthorized();
    }
}
