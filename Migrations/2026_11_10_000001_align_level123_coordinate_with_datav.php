<?php

use App\Apps\CmsproRegion\Services\RegionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库变更脚本
 *
 * 版本号: V2026111001
 * 变更描述: 将 level1-3（省/市/区县）共 565 条与阿里 DataV 官方 center 偏差 >=1km 的经纬度对齐为官方值。
 *           背景：批量自动更新坐标依赖百度 JSAPI 正地址解析，通用短名（如「南区」「中心区」「新民社区」）
 *           会被跨省误命中，且 JSAPI 只回调 Point、拿不到 level/confidence，错误结果无法在代码侧识别。
 *           典型硬错：香港特别行政区及其东区/南区/北区 5 条共用 116.653993,40.128566（河北承德一带），
 *           中西区被解析到台湾（120.189869,22.991988）；西藏班戈县偏 193km、通辽科尔沁区偏 172km、
 *           工布江达县被赋成米林市坐标偏 124km。省级坐标错误会连带使「父子距离校验」误判其全部下级，
 *           故必须自顶向下先校正 level1-3，再以校正后的基准重判 level4/5。
 * 影响表: app_cmspro_region_region（UPDATE level IN (1,2,3) 的 lon/lat/update_time，共 565 行）
 * 变更类型: DML（纯 UPDATE，无 INSERT/DELETE，不改结构、不改 parent_id，闭包表无需重建）
 * 创建日期: 2026-11-10
 * 关联需求: level1-3 坐标与官方数据源对齐（DataV 比对：偏差 >=5km 142 条、1-5km 425 条）
 * 数据源: Data/region_coordinate_datav_align_20261110.csv（9 列，按表头名取列，对列序鲁棒；
 *         同时保留原值作为 down() 回滚依据）。文件缺失时 up()/down() 均静默跳过。
 * 坐标系: DataV 与库内同为 GCJ-02（既有 RegionService::applyGeojsonCoordinate 直接写库、不做转换即为印证），
 *         故本迁移不引入任何坐标系换算。
 * 人工排除: 460302 西沙区、460303 南沙区不在本批内 —— DataV 将两区标为同一点 112.891018,9.543575
 *           （南沙永暑礁），自身数据不可靠；库内 112.334,16.8327（西沙永兴岛）对西沙区正确、
 *           对南沙区错误，两区不能互相覆盖，需人工单独定值。
 *           另有 8 条 DataV 未收录的新设区划（米林市、错那市、和康县、和安县、新星市、白杨市、
 *           草湖市、两江新区）无官方值可比，本批不动。其中 540481 米林市库内坐标与 540421
 *           工布江达县完全相同，属真错，待有权威数据源后单独处理。
 * 风险等级: 中（565 行 UPDATE，全部有原值可还原；不新增/删除节点，不影响闭包表与层级结构）
 *
 * 回滚方案: 见 down()。按 CSV 内「原经度/原纬度」将同一批 code 的坐标还原为变更前值，
 *           同样走 CASE code WHEN 批量 UPDATE + 事务，还原后清缓存。
 *           注意：若变更前后的坐标已被「在线同步官方数据」或「导入快照包」全量覆盖，
 *           本迁移的 down() 还原结果同样会被抹掉，恢复方式改为重新执行在线同步/导入快照。
 */
return new class extends Migration
{
    /**
     * 单批 UPDATE 行数：CASE WHEN 分支过多会撑大 SQL 体积，分批控制在安全范围
     */
    private int $chunkSize = 200;

    /**
     * code 集合 IN 查询分片大小
     */
    private int $codeChunk = 500;

    /**
     * 浮点相等判定容差，避免 decimal(10,7) 读回后的微小误差导致幂等失效
     */
    private float $epsilon = 0.0000001;

    public function up(): void
    {
        $this->applyCoordinate('new');
    }

    public function down(): void
    {
        $this->applyCoordinate('old');
    }

    /**
     * 按 CSV 计划批量写入坐标
     *
     * @param string $direction 'new' 取目标值（up），'old' 取原值（down 回滚）
     */
    protected function applyCoordinate(string $direction): void
    {
        // 测试库以「干净库」为前提，本批生产坐标订正数据不得灌入，否则污染各用例的记录数与坐标断言
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('app_cmspro_region_region')) {
            return;
        }

        $rows = $this->planRows();
        if ($rows === []) {
            Log::info('区划坐标对齐迁移：数据文件缺失或无有效行，跳过');
            return;
        }

        $lonKey = $direction === 'new' ? 'new_lon' : 'old_lon';
        $latKey = $direction === 'new' ? 'new_lat' : 'old_lat';

        $payload = [];
        $skipped = 0;
        foreach ($this->withCurrentCoordinate($rows) as $row) {
            $targetLon = (float) $row[$lonKey];
            $targetLat = (float) $row[$latKey];
            // 幂等：当前值已等于目标值时不写库，避免重复执行产生无意义 UPDATE 与 update_time 抖动
            if ($row['cur_lon'] !== null && $row['cur_lat'] !== null
                && abs($row['cur_lon'] - $targetLon) < $this->epsilon
                && abs($row['cur_lat'] - $targetLat) < $this->epsilon) {
                $skipped++;
                continue;
            }
            $payload[] = ['code' => $row['code'], 'lon' => $targetLon, 'lat' => $targetLat];
        }

        if ($payload !== []) {
            $now = date('Y-m-d H:i:s');
            DB::transaction(function () use ($payload, $now) {
                foreach (array_chunk($payload, $this->chunkSize) as $chunk) {
                    $this->updateChunk($chunk, $now);
                }
            });
        }

        app(RegionService::class)->clearCache();

        Log::info('区划坐标对齐迁移完成', [
            '方向' => $direction === 'new' ? '对齐 DataV 官方值' : '回滚至变更前原值',
            '更新' => count($payload),
            '幂等跳过' => $skipped,
        ]);
    }

    /**
     * 批量 UPDATE 单批坐标
     *
     * 用 CASE code WHEN 一条语句写完一批，避免在循环中执行单条 SQL。
     * code 已在 planRows() 校验为 6 位纯数字、坐标已 (float) 强转，拼接不存在注入面。
     *
     * @param array<int, array{code: string, lon: float, lat: float}> $chunk
     */
    protected function updateChunk(array $chunk, string $now): void
    {
        $codes = [];
        $lonCases = [];
        $latCases = [];
        foreach ($chunk as $item) {
            $codes[] = "'{$item['code']}'";
            $lonCases[] = "WHEN '{$item['code']}' THEN {$item['lon']}";
            $latCases[] = "WHEN '{$item['code']}' THEN {$item['lat']}";
        }

        $sql = 'UPDATE app_cmspro_region_region SET '
            . 'lon = CASE code ' . implode(' ', $lonCases) . ' ELSE lon END, '
            . 'lat = CASE code ' . implode(' ', $latCases) . ' ELSE lat END, '
            . "update_time = '{$now}' "
            . 'WHERE code IN (' . implode(',', $codes) . ')';

        DB::update($sql);
    }

    /**
     * 读取对齐计划 CSV，返回 [code, old_lon, old_lat, new_lon, new_lat] 行集合
     *
     * 按表头名定位列，对列序鲁棒；首列去 UTF-8 BOM；过滤 code 非 6 位纯数字或坐标非数值的脏行。
     *
     * @return array<int, array{code: string, old_lon: float, old_lat: float, new_lon: float, new_lat: float}>
     */
    protected function planRows(): array
    {
        $path = __DIR__ . '/../Data/region_coordinate_datav_align_20261110.csv';
        // 必须先判存在再 fopen：文件缺失时 fopen 触发的 E_WARNING 会被框架错误处理器
        // 转成 ErrorException 抛出，使「静默跳过」失效并导致站点升级中断
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $index = array_flip(array_map('trim', $header));
        $required = ['区划code' => null, '原经度' => null, '原纬度' => null, '新经度' => null, '新纬度' => null];
        foreach ($required as $name => $_) {
            if (! isset($index[$name])) {
                fclose($handle);
                return [];
            }
            $required[$name] = $index[$name];
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $code = trim((string) ($line[$required['区划code']] ?? ''));
            if (! preg_match('/^\d{6}$/', $code)) {
                continue;
            }

            $values = [];
            $valid = true;
            foreach (['old_lon' => '原经度', 'old_lat' => '原纬度', 'new_lon' => '新经度', 'new_lat' => '新纬度'] as $key => $col) {
                $raw = trim((string) ($line[$required[$col]] ?? ''));
                if (! is_numeric($raw)) {
                    $valid = false;
                    break;
                }
                $values[$key] = (float) $raw;
            }
            if (! $valid) {
                continue;
            }

            $rows[] = array_merge(['code' => $code], $values);
        }

        fclose($handle);
        return $rows;
    }

    /**
     * 为计划行补上库内当前坐标，供幂等判定（分片 IN 查询，避免逐条查库）
     *
     * @param array<int, array{code: string}> $rows
     * @return array<int, array>
     */
    protected function withCurrentCoordinate(array $rows): array
    {
        $current = [];
        foreach (array_chunk(array_column($rows, 'code'), $this->codeChunk) as $chunk) {
            $found = DB::table('app_cmspro_region_region')
                ->whereIn('code', $chunk)
                ->get(['code', 'lon', 'lat']);
            foreach ($found as $item) {
                $current[$item->code] = [
                    'lon' => $item->lon === null ? null : (float) $item->lon,
                    'lat' => $item->lat === null ? null : (float) $item->lat,
                ];
            }
        }

        foreach ($rows as &$row) {
            $row['cur_lon'] = $current[$row['code']]['lon'] ?? null;
            $row['cur_lat'] = $current[$row['code']]['lat'] ?? null;
        }
        unset($row);

        return $rows;
    }
};
