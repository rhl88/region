<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use App\Apps\CmsproRegion\Models\Region;
use App\Apps\CmsproRegion\Models\RegionClosure;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * 导入快照包测试（站点间数据分享）
 *
 * 与「导出快照包」配对：接收方将他人分享的 cmspro-region-data-{version}.zip
 * 直接上传导入，全量替换本地区划数据（复用在线同步的落库链路与进度机制），
 * 无需经过版本服务器。
 *
 * 覆盖：
 * 1. 上传校验：未登录拦截、非 zip 拒绝
 * 2. 契约校验：空包/孤儿引用等非法数据拒绝，且本地原数据零影响
 * 3. 全量替换落库：parent_code→parent_id、闭包表重建、status/lon/lat 保留、版本号自愈记录
 * 4. 端到端分享闭环：导出快照包 → 清库 → 上传导入 → 数据完整还原
 * 5. 进度令牌轮询（复用 sync-progress 机制）
 */
class SnapshotImportTest extends RegionTestCase
{
    /** @var array<int,string> 测试产生的临时文件 */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach (glob(storage_path('app/cmspro-region-data-*.zip')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * 分享包样本：四级 5 条（含 status=0 与无坐标记录），parent_code 契约。
     */
    private function sampleSnapshotRegions(): array
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
     * 构造快照包上传文件（regions.json + 可选 meta.json）。
     */
    private function buildSnapshotZip(array $regions, ?string $version = '20261106001'): UploadedFile
    {
        $zipPath = sys_get_temp_dir() . '/cmspro-import-snapshot-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('regions.json', (string) json_encode($regions, JSON_UNESCAPED_UNICODE));
        if ($version !== null) {
            $zip->addFromString('meta.json', (string) json_encode([
                'version' => $version,
                'count' => count($regions),
                'stats' => ['province' => 2, 'city' => 1, 'district' => 1, 'street' => 1],
                'generated_at' => date('Y-m-d H:i:s'),
                'format' => 'code,name,level,parent_code,status,lon,lat',
            ], JSON_UNESCAPED_UNICODE));
        }
        $zip->close();

        $this->tempFiles[] = $zipPath;

        return new UploadedFile($zipPath, 'cmspro-region-data-20261106001.zip', 'application/zip', null, true);
    }

    /**
     * 预置本地旧数据（导入前状态）。
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

    /**
     * 上传快照包并返回终态进度。
     *
     * 导入接口已改为「立即返回进度令牌 + 后台执行」，结果与错误信息统一从
     * sync-progress 轮询获取；测试环境 runInBackground 同步执行，POST 返回时任务已完成。
     *
     * @return array{state:string,percent:int,stage:string,message:string}
     */
    private function runImport(UploadedFile $file): array
    {
        $token = 'import_test_' . uniqid();

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/import-snapshot', [
                'file' => $file,
                'progress_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.started', true)
            ->assertJsonPath('data.progress_token', $token);

        return (array) $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->json('data');
    }

    public function test_未登录访问导入接口被拦截(): void
    {
        $this->postJson('/api/admin/cmspro/region/import-snapshot')->assertUnauthorized();
    }

    public function test_导入接口拒绝非zip文件(): void
    {
        $txtPath = sys_get_temp_dir() . '/cmspro-not-zip-' . uniqid() . '.txt';
        file_put_contents($txtPath, 'not a zip');
        $this->tempFiles[] = $txtPath;

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/import-snapshot', [
                'file' => new UploadedFile($txtPath, 'fake.txt', 'text/plain', null, true),
            ])
            ->assertStatus(422);
    }

    public function test_导入_缺少regions_json时拒绝且原数据零影响(): void
    {
        $this->seedLocalOldData();

        // 空 zip（无任何文件）
        $zipPath = sys_get_temp_dir() . '/cmspro-empty-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'no regions');
        $zip->close();
        $this->tempFiles[] = $zipPath;

        $progress = $this->runImport(new UploadedFile($zipPath, 'empty.zip', 'application/zip', null, true));
        $this->assertSame('error', $progress['state']);
        $this->assertStringContainsString('regions.json', (string) $progress['message']);

        $this->assertSame(2, Region::count());
        $this->assertSame(2, Region::where('code', 'like', '999%')->count());
    }

    public function test_导入_契约非法孤儿引用时拒绝且原数据零影响(): void
    {
        $this->seedLocalOldData();

        $badRegions = [
            ['code' => '110000', 'name' => '北京市', 'level' => 1, 'parent_code' => '0', 'status' => 1],
            ['code' => '110101', 'name' => '东城区', 'level' => 3, 'parent_code' => '110100', 'status' => 1],
        ];

        $progress = $this->runImport($this->buildSnapshotZip($badRegions));
        $this->assertSame('error', $progress['state']);
        $this->assertStringContainsString('不存在的上级编码', (string) $progress['message']);

        $this->assertSame(2, Region::count());
        $this->assertSame(2, Region::where('code', 'like', '999%')->count());
        $this->assertSame(1, RegionClosure::count());
    }

    public function test_导入_全量替换落库成功并记录包内版本号(): void
    {
        $this->seedLocalOldData();

        $progress = $this->runImport($this->buildSnapshotZip($this->sampleSnapshotRegions(), '20261106001'));
        $this->assertSame('done', $progress['state'], '导入未完成');
        $this->assertSame(100, $progress['percent']);

        // 全量替换：旧数据消失，新数据按契约写入
        $this->assertSame(0, Region::where('code', 'like', '999%')->count());
        $this->assertSame(5, Region::count());

        $beijing = Region::where('code', '110000')->firstOrFail();
        $city = Region::where('code', '110100')->firstOrFail();
        $district = Region::where('code', '110101')->firstOrFail();
        $street = Region::where('code', '110101001')->firstOrFail();

        $this->assertSame(0, $beijing->parent_id);
        $this->assertSame($beijing->id, $city->parent_id);
        $this->assertSame($city->id, $district->parent_id);
        $this->assertSame($district->id, $street->parent_id);
        $this->assertSame(0, Region::where('code', '120000')->value('status'));
        $this->assertSame(116.4, $beijing->lon);
        $this->assertNull($district->lon);

        // 闭包表重建：5 节点共 11 条闭包记录
        $this->assertSame(11, RegionClosure::count());

        // 版本号自愈写入（配置组缺失时自动建组）
        $this->assertSame(1, ConfigGroup::where('code', 'app_cmspro_region_sync_settings')->count());
        $this->assertSame(
            '20261106001',
            (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value')
        );
    }

    public function test_导入_无meta_json时版本号回退snapshot前缀(): void
    {
        $progress = $this->runImport($this->buildSnapshotZip($this->sampleSnapshotRegions(), null));
        $this->assertSame('done', $progress['state']);

        $version = (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value');
        $this->assertMatchesRegularExpression('/^snapshot-\d{14}$/', $version);
    }

    /**
     * 端到端分享闭环：站点A导出快照包 → 站点B（本地）清库后上传导入 → 数据完整还原。
     */
    public function test_端到端_导出包可直接导入还原数据(): void
    {
        // 站点A：预置数据并导出快照包
        foreach ($this->sampleSnapshotRegions() as $row) {
            $parent = $row['parent_code'] === '0' ? 0 : (int) Region::where('code', $row['parent_code'])->value('id');
            Region::create([
                'code' => $row['code'], 'name' => $row['name'], 'level' => $row['level'],
                'parent_id' => $parent, 'status' => $row['status'],
                'lon' => $row['lon'], 'lat' => $row['lat'],
            ]);
        }

        $exportResponse = $this->asAdmin()
            ->get('/api/admin/cmspro/region/export-snapshot?version=20261106001');
        $exportResponse->assertOk();
        $exportedZipPath = $exportResponse->baseResponse->getFile()->getPathname();
        $this->assertFileExists($exportedZipPath);

        // 站点B（同一测试库模拟）：清空全部数据，回到「从未初始化」状态
        Region::query()->delete();
        RegionClosure::query()->delete();
        ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->delete();
        $this->assertSame(0, Region::count());

        // 将导出的包原样上传导入
        $sharedFile = new UploadedFile($exportedZipPath, basename($exportedZipPath), 'application/zip', null, true);
        $this->tempFiles[] = $exportedZipPath;

        $progress = $this->runImport($sharedFile);
        $this->assertSame('done', $progress['state']);

        // 数据完整还原：5 条记录 + 层级 + 闭包 + 版本号
        $this->assertSame(5, Region::count());
        $beijing = Region::where('code', '110000')->firstOrFail();
        $street = Region::where('code', '110101001')->firstOrFail();
        $this->assertSame(11, RegionClosure::count());
        $this->assertSame(
            4,
            RegionClosure::where('descendant', $street->id)->count(),
            '街道节点应含自身与三级祖先共 4 条闭包记录'
        );
        $this->assertSame(
            '20261106001',
            (string) ConfigItem::where('code', 'app_cmspro_region_data_sync_version')->value('value')
        );
    }

    public function test_导入_传入进度令牌后可轮询到终态(): void
    {
        $token = 'import_test_' . uniqid();

        $this->asAdmin()
            ->postJson('/api/admin/cmspro/region/import-snapshot', [
                'file' => $this->buildSnapshotZip($this->sampleSnapshotRegions()),
                'progress_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.started', true)
            ->assertJsonPath('data.progress_token', $token);

        // 测试环境任务同步执行（真实环境为响应返回后后台执行），此处应读到终态 done/100
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/sync-progress?progress_token=' . $token)
            ->assertOk()
            ->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.percent', 100);
    }

    public function test_管理页渲染导入快照包按钮(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('importSnapshotBtn', false)
            ->assertSee('导入快照包', false)
            ->assertSee('import-snapshot', false);
    }
}
