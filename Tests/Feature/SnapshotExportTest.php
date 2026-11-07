<?php

namespace App\Apps\CmsproRegion\Tests\Feature;

use App\Apps\CmsproRegion\Models\Region;
use App\Apps\Versionsvr\Services\RegionDataService;
use ZipArchive;

/**
 * 导出全量快照包测试（阶段二：生产端）
 *
 * 覆盖「导出快照包」功能的服务契约与接口契约：
 * 1. 导出前提校验：空库（无省级数据）拒绝导出
 * 2. 导出包契约：ZIP 内含 regions.json + meta.json，与 Versionsvr 区划数据服务
 *    （阶段一）的校验规则完全对齐——导出包上传版本服务器后可直接校验发布
 * 3. parent_id → parent_code 转换：顶级为 '0'，子级为父记录 code
 * 4. 版本号：自定义 / 自动生成（YYYYMMDD001）/ 非法格式拒绝
 * 5. 端到端契约：导出的 regions.json 可被 Versionsvr RegionDataService::validateRegions 直接校验通过
 */
class SnapshotExportTest extends RegionTestCase
{
    /**
     * 种子数据：两级省（1 启用带坐标、1 禁用无坐标）+ 市 + 区县（无坐标）+ 街道。
     *
     * 覆盖契约关注点：顶级 parent_code='0'、status 0/1 均导出、lon/lat null 与有值均导出、
     * 四级层级 parent_id → parent_code 转换。
     */
    private function seedRegions(): array
    {
        $beijing = Region::create([
            'code' => '110000', 'name' => '北京市', 'level' => 1,
            'parent_id' => 0, 'status' => 1, 'lon' => 116.4, 'lat' => 39.9,
        ]);
        $tianjin = Region::create([
            'code' => '120000', 'name' => '天津市', 'level' => 1,
            'parent_id' => 0, 'status' => 0, 'lon' => null, 'lat' => null,
        ]);
        $city = Region::create([
            'code' => '110100', 'name' => '市辖区', 'level' => 2,
            'parent_id' => $beijing->id, 'status' => 1, 'lon' => 116.6, 'lat' => 40.1,
        ]);
        $district = Region::create([
            'code' => '110101', 'name' => '东城区', 'level' => 3,
            'parent_id' => $city->id, 'status' => 1, 'lon' => null, 'lat' => null,
        ]);
        $street = Region::create([
            'code' => '110101001', 'name' => '东华门街道', 'level' => 4,
            'parent_id' => $district->id, 'status' => 1, 'lon' => null, 'lat' => null,
        ]);

        return compact('beijing', 'tianjin', 'city', 'district', 'street');
    }

    /**
     * 从下载响应中提取 ZIP 内容并解出 regions.json / meta.json。
     *
     * 说明：Symfony BinaryFileResponse::getContent() 固定返回 false（内容流式发送），
     * 因此从 baseResponse->getFile() 直接读取快照包文件。
     *
     * @return array{regions: array, meta: array}
     */
    private function extractSnapshotZip($response): array
    {
        $base = $response->baseResponse;
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $base, '导出响应不是文件下载流');

        $zipPath = $base->getFile()->getPathname();
        $this->assertFileExists($zipPath, '导出包文件不存在');
        $this->assertNotEmpty(filesize($zipPath), '导出包内容为空');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CHECKCONS) === true, '导出包不是合法 ZIP');

        $regionsRaw = (string) $zip->getFromName('regions.json');
        $metaRaw = (string) $zip->getFromName('meta.json');
        $zip->close();

        $this->assertNotSame('', $regionsRaw, '导出包缺少 regions.json');
        $this->assertNotSame('', $metaRaw, '导出包缺少 meta.json');

        $regions = json_decode($regionsRaw, true);
        $meta = json_decode($metaRaw, true);
        $this->assertIsArray($regions, 'regions.json 不是合法 JSON 数组');
        $this->assertIsArray($meta, 'meta.json 不是合法 JSON 对象');

        return ['regions' => $regions, 'meta' => $meta];
    }

    /**
     * 清理测试残留的快照包（测试环境无真实响应发送，deleteFileAfterSend 不触发）。
     */
    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/cmspro-region-data-*.zip')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_未登录访问导出接口被拦截(): void
    {
        $this->getJson('/api/admin/cmspro/region/export-snapshot')
            ->assertUnauthorized();
    }

    public function test_管理页渲染导出快照包按钮(): void
    {
        $this->asAdmin()
            ->get('/admin/cmspro/region')
            ->assertOk()
            ->assertSee('exportSnapshotBtn', false)
            ->assertSee('导出快照包', false)
            ->assertSee('export-snapshot', false);
    }

    public function test_空库导出快照包返回错误(): void
    {
        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/export-snapshot')
            ->assertOk()
            ->assertJson(['code' => 1])
            ->assertJsonStructure(['code', 'message']);
    }

    public function test_空库但有非省级数据同样拒绝导出(): void
    {
        // 仅有区县数据（引用断裂的脏库场景）也不能导出
        Region::create([
            'code' => '110101', 'name' => '东城区', 'level' => 3,
            'parent_id' => 0, 'status' => 1,
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/export-snapshot')
            ->assertOk()
            ->assertJson(['code' => 1]);
    }

    public function test_导出快照包返回ZIP下载流(): void
    {
        $this->seedRegions();

        $response = $this->asAdmin()
            ->get('/api/admin/cmspro/region/export-snapshot');

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.zip', $disposition);
        $this->assertStringContainsString('cmspro-region-data-', $disposition);
    }

    public function test_导出包regions_json契约完整(): void
    {
        $this->seedRegions();

        $response = $this->asAdmin()
            ->get('/api/admin/cmspro/region/export-snapshot?version=20261106001');

        $response->assertOk();

        $extracted = $this->extractSnapshotZip($response);
        $regions = $extracted['regions'];

        // 全量导出：5 条种子记录全部导出
        $this->assertCount(5, $regions);

        // 按 level + code 排序（与 Versionsvr 发布包排序规则一致，父级先于子级）
        $this->assertSame(
            [
                ['110000', '北京市', 1, '0', 1, 116.4, 39.9],
                ['120000', '天津市', 1, '0', 0, null, null],
                ['110100', '市辖区', 2, '110000', 1, 116.6, 40.1],
                ['110101', '东城区', 3, '110100', 1, null, null],
                ['110101001', '东华门街道', 4, '110101', 1, null, null],
            ],
            array_map(static fn (array $r): array => [
                $r['code'], $r['name'], $r['level'], $r['parent_code'],
                $r['status'], $r['lon'], $r['lat'],
            ], $regions)
        );

        // 字段集合固定（契约字段：code/name/level/parent_code/status/lon/lat）
        foreach ($regions as $region) {
            $this->assertSame(
                ['code', 'name', 'level', 'parent_code', 'status', 'lon', 'lat'],
                array_keys($region)
            );
        }
    }

    public function test_导出包meta_json统计与实际一致(): void
    {
        $this->seedRegions();

        $extracted = $this->extractSnapshotZip(
            $this->asAdmin()->get('/api/admin/cmspro/region/export-snapshot?version=20261106001')
        );
        $meta = $extracted['meta'];

        $this->assertSame('20261106001', $meta['version']);
        $this->assertSame(5, $meta['count']);
        $this->assertSame(
            ['province' => 2, 'city' => 1, 'district' => 1, 'street' => 1, 'village' => 0],
            $meta['stats']
        );
        // 日期时间统一 Y-m-d H:i:s 格式
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $meta['generated_at']);
        $this->assertSame('code,name,level,parent_code,status,lon,lat', $meta['format']);
    }

    public function test_自定义版本号写入文件名与meta(): void
    {
        $this->seedRegions();

        $response = $this->asAdmin()
            ->get('/api/admin/cmspro/region/export-snapshot?version=v2026.11.06');

        $response->assertOk();

        $this->assertStringContainsString(
            'cmspro-region-data-v2026.11.06.zip',
            (string) $response->headers->get('Content-Disposition')
        );

        $extracted = $this->extractSnapshotZip($response);
        $this->assertSame('v2026.11.06', $extracted['meta']['version']);
    }

    public function test_未指定版本号时自动生成YYYYMMDD001格式(): void
    {
        $this->seedRegions();

        $response = $this->asAdmin()
            ->get('/api/admin/cmspro/region/export-snapshot');

        $response->assertOk();

        $extracted = $this->extractSnapshotZip($response);
        $version = (string) $extracted['meta']['version'];

        // YYYYMMDD + 3 位序号，共 11 位数字
        $this->assertMatchesRegularExpression('/^\d{11}$/', $version);
        $this->assertSame(date('Ymd'), substr($version, 0, 8));

        $this->assertStringContainsString(
            'cmspro-region-data-' . $version . '.zip',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_非法版本号被拒绝(): void
    {
        $this->seedRegions();

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/export-snapshot?version=../evil')
            ->assertOk()
            ->assertJson(['code' => 1]);

        $this->asAdmin()
            ->getJson('/api/admin/cmspro/region/export-snapshot?version=' . str_repeat('a', 33))
            ->assertOk()
            ->assertJson(['code' => 1]);
    }

    public function test_导出包可被Versionsvr区划数据校验直接通过(): void
    {
        $this->seedRegions();

        $extracted = $this->extractSnapshotZip(
            $this->asAdmin()->get('/api/admin/cmspro/region/export-snapshot?version=20261106001')
        );

        // 端到端契约：导出的 regions.json 原样送入版本服务器校验规则，必须通过且统计一致
        $service = new RegionDataService();
        $validated = $service->validateRegions($extracted['regions']);

        $this->assertSame(5, $validated['count']);
        $this->assertSame(
            ['province' => 2, 'city' => 1, 'district' => 1, 'street' => 1, 'village' => 0],
            $validated['stats']
        );

        // 校验后排序与导出排序一致（导出端已按 level + code 排序，Versionsvr 不会重排）
        $this->assertSame(
            array_column($extracted['regions'], 'code'),
            array_column($validated['regions'], 'code')
        );
    }
}
