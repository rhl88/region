# CmsPro 中国行政区划应用扩展指南

> 版本：1.12.5 | 更新日期：2026-11-10
> 适用系统：CmsPro v5.0.0+
> 应用ID：`cmspro.region`

***

## 一、应用概述

中国行政区划应用基于（[中国国家地名信息库](https://dmfw.mca.gov.cn/XzqhVersionPublish.html)）提供省、市、区县三级联动查询（默认）和街道/乡镇四级数据（可选），采用单表+闭包表存储架构，支持高效的层级关系查询。核心服务注册为Laravel单例，可被任何应用通过依赖注入、钩子或API三种方式引用。

### 核心特性

- **三级联动**：默认支持省/市/区县三级区划数据（约3200+条，含港澳台）
- **四级可选**：可配置开启街道/乡镇级数据（约4万+条），按省份分步导入，避免超时
- **五级可选**：支持行政村/社区级数据（约54万条，12位编码），经「在线同步官方数据」或「导入快照包」获取
- **闭包表架构**：单表存储+闭包表加速，支持高效的层级路径查询
- **三种引用方式**：依赖注入、Filter钩子、API接口，灵活适配不同场景
- **缓存机制**：查询结果自动缓存24小时，数据更新时自动清除；街道数据支持本地文件缓存
- **数据管理**：后台提供数据导入/更新、街道分步导入、缓存清除、级联预览、数据对比更新等功能
- **地理位置数据**：支持导入城市和区县的经纬度信息，便于地图定位和距离计算
- **快照导出**：将库中全量区划导出为标准化 ZIP 快照包（regions.json + meta.json），供上传版本服务器（Versionsvr「区划数据」服务）发布分发，使区划数据更新与本应用发版解耦
- **在线同步官方数据**：从版本服务器在线同步官方区划数据（全量替换落库：单事务全删重插 + 闭包表重建），站点数据初始化从约 1 小时降至 1 分钟内，任一步失败整体回滚零影响
- **导入快照包**：接收其他站点分享的快照包 ZIP 直接上传导入（全量替换落库，与在线同步共用校验链路与进度机制），支持站点间点对点分享数据集

### 数据源

数据来源于 **中国国家地名信息库**（[中国国家地名信息库](https://dmfw.mca.gov.cn/XzqhVersionPublish.html)），通过民政部官方API获取：

| 数据 | API地址 | 说明 |
|------|---------|------|
| 三级数据 | `https://dmfw.mca.gov.cn/9095/xzqh/getList` | 省/市/区县三级数据，约3400条 |
| 街道数据 | `https://dmfw.mca.gov.cn/xzqh/getCodeList2` | 街道/乡镇四级数据，约4万+条 |
| `Data/list.json` | - | 三级数据本地缓存文件（树形三级格式，含港澳台，34 省/333 市/2872 区县） |
| `Data/code/*.json` | - | 街道数据本地缓存文件，按区县代码命名 |
| `Data/地理位置编码.xlsx` | - | 地理位置数据文件，包含省、市、区县的经纬度信息（约3200+条，含港澳台） |

***

## 二、安装与配置

### 2.1 安装应用

通过CmsPro后台「应用市场」安装中国行政区划应用，或手动将应用包上传安装。安装过程中会自动创建数据库表（`app_cmspro_region_region` 和 `app_cmspro_region_closure`）。

> **注意**：安装后数据表为空，需进入后台「行政区划 → 数据管理」页面手动导入数据。

### 2.2 导入三级数据

安装完成后，在后台「行政区划 → 数据管理」页面中，推荐点击「**一键导入**」按钮（使用本地数据包自动完成三级区划、街道数据、地理位置全量导入，无需联网）；也可点击「导入三级数据」单项按钮仅导入省/市/区县数据。由于三级数据有3200+条（含港澳台），系统采用 **按省份分步导入** 策略：

1. 前端获取省份列表（34个）
2. 逐个省份通过 AJAX 请求导入，每次请求处理一个省份
3. 进度条实时显示导入进度，日志记录每个省份的导入结果
4. 每个省份独立请求，即使服务器配置较低也不会超时

### 2.3 启用街道级数据（可选）

如需使用街道/乡镇四级数据，在CmsPro后台「系统设置 → 区划设置」中开启「启用街道级数据」选项。

| 配置项 | 说明 |
|--------|------|
| 启用街道级数据 | 开启后可导入街道/乡镇四级数据（约4万+条），数据量较大，请按需开启 |

### 2.4 导入街道数据

开启街道级数据后，在后台页面点击「导入街道数据」按钮。由于街道数据量较大（约4万+条），系统采用 **按省份分步导入** 策略：

1. 前端获取省份列表（从数据库读取所有省份）
2. 逐个省份获取区县列表
3. 按区县逐个导入街道数据，调用民政部API获取数据
4. 进度条实时显示导入进度，日志记录每个区县的导入结果

#### 街道数据缓存机制

系统采用智能缓存机制，避免重复请求API：

1. **优先读取缓存**：检查 `Data/code/{区县代码}.json` 是否存在
2. **已有缓存**：直接读取本地文件，不请求API
3. **无缓存**：调用民政部API获取数据，保存到缓存文件后再导入数据库
4. **分页处理**：API支持分页，自动循环获取所有页数据

#### 民政部街道API参数

| 参数 | 值 | 说明 |
|------|------|------|
| parentCode | 区县代码+000000 | 如 `110101000000` |
| placeCode | 空 | 预留参数 |
| title | 空 | 预留参数 |
| tableName | Xzqh20251231 | 数据版本表名 |
| type | 3 | 街道级数据标识 |
| pageNum | 1, 2, 3... | 页码 |
| pageSize | 20 | 每页条数 |

### 2.5 数据更新

导入操作采用 **差异化更新** 策略，基于 `code` 字段匹配，保留已有记录的 ID，外键不会断裂：

| 场景 | 处理方式 |
|------|----------|
| 新增区划 | 源数据中有新 code，插入新记录 |
| 改名 | code 已存在但 name 变化，更新 name |
| 升级/降级 | code 已存在但 level 或 parent 变化，更新 level、parent_id |
| 撤销/删除 | 数据库中有但源数据中不存在，标记 status=0 禁用 |
| 无变化 | code 和 name 均未变，跳过不处理 |

- **三级数据**：点击「导入三级数据」执行差异化更新
- **街道数据**：点击「导入街道数据」按省份差异化更新，每次请求处理一个省份
- **数据对比更新**：点击「更新数据」按钮，可对比民政部API最新数据与本地数据的差异，确认后更新

### 2.6 导入地理位置数据（可选）

如需使用城市和区县的经纬度信息，在后台页面点击「导入地理位置」按钮。数据来源为本地Excel文件 `Data/地理位置编码.xlsx`，包含约3200+条省、市、区县的经纬度信息（含港澳台）。

系统采用 **按省份分步导入** 策略：

1. 前端获取省份列表（从Excel文件读取）
2. 逐个省份通过 AJAX 请求导入，每次请求处理一个省份
3. 进度条实时显示导入进度，日志记录每个省份的导入结果
4. 每个省份独立请求，避免超时

**导入逻辑**：

| 场景 | 处理方式 |
|------|----------|
| 已存在经纬度且无变化 | 跳过不处理 |
| 已存在经纬度但有变化 | 更新经纬度 |
| 不存在经纬度 | 添加经纬度 |
| 数据库中无对应区划记录 | 跳过并统计 |

**Excel文件格式**：

| 列 | 字段 | 说明 |
|------|------|------|
| A | district_id | 区县编码（6位） |
| B | province | 省份名称 |
| C | city | 城市名称 |
| D | city_geocode | 城市编码（6位） |
| E | district | 区县名称 |
| F | district_geocode | 区县编码（6位） |
| G | lon | 经度 |
| H | lat | 纬度 |

***

## 三、其他应用引用

### 3.1 依赖注入方式（推荐）

在控制器或服务中通过依赖注入使用：

```php
use App\Apps\CmsproRegion\Services\RegionService;

class YourController extends Controller
{
    public function __construct(
        protected RegionService $regionService
    ) {}

    public function index()
    {
        // 获取所有省份
        $provinces = $this->regionService->getProvinces();

        // 获取北京市下的区县
        $children = $this->regionService->getChildren('110000');

        // 获取东城区的完整路径
        $path = $this->regionService->getPath('110101');

        // 获取级联选择器数据（四级）
        $cascader = $this->regionService->getCascaderData(4);
    }
}
```

### 3.2 app() 辅助函数方式

```php
$regionService = app(\App\Apps\CmsproRegion\Services\RegionService::class);
$provinces = $regionService->getProvinces();
```

### 3.3 Filter 钩子方式（松耦合）

通过 HookManager 调用，无需直接引用应用类，降低应用间耦合：

```php
use App\Services\HookManager;

// 获取级联选择器数据
$cascaderData = $hookManager->applyFilter('region.cascader_data', []);

// 获取省份列表
$provinces = $hookManager->applyFilter('region.provinces', []);

// 获取子级区划（传入父级 code）
$children = $hookManager->applyFilter('region.children', '110000');

// 获取完整路径（传入区划 code）
$path = $hookManager->applyFilter('region.path', '110101');
```

**可用钩子列表**：

| 钩子名称 | 参数 | 返回值 | 说明 |
|----------|------|--------|------|
| `region.cascader_data` | 无 | `array` | 省市区三级级联数据 |
| `region.provinces` | 无 | `Collection` | 所有省份列表 |
| `region.children` | `string` 父级code | `Collection` | 指定父级的子级列表 |
| `region.path` | `string` 区划code | `Collection` | 完整路径（省→市→区） |

### 3.4 API 接口方式

适用于前端页面或跨系统调用：

| 接口 | 方法 | 参数 | 说明 |
|------|------|------|------|
| `/api/admin/cmspro/region/provinces` | GET | 无 | 获取所有省份列表 |
| `/api/admin/cmspro/region/children` | GET | `code` 父级区划代码 | 获取子级区划列表 |
| `/api/admin/cmspro/region/tree` | GET | `max_level` 最大层级(1-4) | 获取完整树形数据 |
| `/api/admin/cmspro/region/path` | GET | `code` 区划代码 | 获取完整路径 |
| `/api/admin/cmspro/region/stats` | GET | 无 | 获取数据统计 |
| `/api/admin/cmspro/region/list` | GET | 无 | 区划数据列表（分页） |
| `/api/admin/cmspro/region/import` | POST | 无 | 全量导入三级数据（差异化更新） |
| `/api/admin/cmspro/region/import-by-province` | POST | `province_code` 省份代码 | 按省份导入三级数据（分步导入） |
| `/api/admin/cmspro/region/clear-cache` | POST | 无 | 清除区划缓存 |
| `/api/admin/cmspro/region/street-provinces` | GET | 无 | 获取待导入街道数据的省份列表 |
| `/api/admin/cmspro/region/street-districts` | GET | `province_code` 省份代码 | 获取指定省份下的区县列表 |
| `/api/admin/cmspro/region/import-street` | POST | `province_code` 省份代码 | 按省份导入街道数据（本地缓存文件） |
| `/api/admin/cmspro/region/import-street-by-district` | POST | `district_code` 区县代码 | 按区县导入街道数据（本地缓存文件） |
| `/api/admin/cmspro/region/download-districts` | GET | 无 | 获取待下载区县列表 |
| `/api/admin/cmspro/region/download-district-data` | POST | `district_code` 区县代码 | 下载并缓存指定区县的街道数据 |
| `/api/admin/cmspro/region/download-list-json` | POST | 无 | 下载并缓存三级数据 list.json |
| `/api/admin/cmspro/region/check-github` | GET | 无 | 检测 GitHub 数据源连通性 |
| `/api/admin/cmspro/region/clear-data-files` | POST | 无 | 清除本地数据缓存文件 |
| `/api/admin/cmspro/region/rebuild-closure` | POST | 无 | 重建闭包表数据 |
| `/api/admin/cmspro/region/list-json-provinces` | GET | 无 | 获取 list.json 中的省份列表（数据库为空时的首次导入） |
| `/api/admin/cmspro/region/check-mca` | GET | 无 | 检测民政部API连通性 |
| `/api/admin/cmspro/region/import-from-mca` | POST | 无 | 从民政部API全量导入三级数据（差异化更新） |
| `/api/admin/cmspro/region/diff-three-level-from-mca` | POST | 无 | 对比三级数据差异（API vs 本地） |
| `/api/admin/cmspro/region/diff-street-from-mca` | POST | `province_code` 或 `district_code` | 对比街道数据差异 |
| `/api/admin/cmspro/region/import-street-from-mca` | POST | `province_code` 省份代码 | 从民政部API按省份导入街道数据 |
| `/api/admin/cmspro/region/import-street-by-district-from-mca` | POST | `district_code` 区县代码 | 从民政部API按区县导入街道数据 |
| `/api/admin/cmspro/region/geo-provinces` | GET | 无 | 获取待导入地理位置数据的省份列表 |
| `/api/admin/cmspro/region/import-geo-data` | POST | 无 | 全量导入地理位置数据（保留兼容） |
| `/api/admin/cmspro/region/import-geo-data-by-province` | POST | `province_code` 省份代码 | 按省份导入地理位置数据 |
| `/api/admin/cmspro/region/geo-stats` | GET | 无 | 获取地理位置数据统计 |
| `/api/admin/cmspro/region/geojson-provinces` | GET | 无 | 获取可在线更新坐标（GeoJSON）的省份列表 |
| `/api/admin/cmspro/region/import-geo-data-from-geojson` | POST | `province_code` 省份代码 | 从阿里 DataV GeoJSON 按省在线更新坐标 |
| `/api/admin/cmspro/region/import-hmt-region` | POST | 无 | 港澳台区划补全（修正台湾脏编码、补全港澳区级） |

**API 响应格式**：

```json
{
    "code": 0,
    "message": "success",
    "data": [...]
}
```

### 3.5 声明应用依赖

如果您的应用依赖行政区划应用，在 `manifest.json` 中声明依赖：

```json
{
    "dependencies": {
        "app_ids": ["cmspro.region"],
        "versions": {}
    }
}
```

安装时系统会校验 `cmspro.region` 应用是否已安装且已启用。

***

## 四、RegionService API 参考

### 4.1 getProvinces(): Collection

获取所有省份列表（带缓存）。

```php
$provinces = $regionService->getProvinces();
// Collection [
//     { id: 1, code: "110000", name: "北京市", level: 1 },
//     { id: 2, code: "120000", name: "天津市", level: 1 },
//     ...
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 主键ID |
| code | string | 行政区划代码 |
| name | string | 区划名称 |
| level | int | 层级（1-省） |

### 4.2 getChildren(string $parentCode): Collection

根据父级区划代码获取直接子级列表。

```php
$children = $regionService->getChildren('110000');
// 获取北京市下的所有区县
// Collection [
//     { id: 3, code: "110101", name: "东城区", level: 3 },
//     { id: 4, code: "110102", name: "西城区", level: 3 },
//     ...
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $parentCode | string | 父级区划代码，如 `110000`（北京市） |

**返回字段**：同 `getProvinces`。

### 4.3 getPath(string $code): Collection

根据区划代码获取完整路径（从省到当前节点）。

```php
$path = $regionService->getPath('110101');
// Collection [
//     { id: 1, code: "110000", name: "北京市", level: 1 },
//     { id: 3, code: "110101", name: "东城区", level: 3 },
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $code | string | 区划代码，如 `110101`（东城区） |

**返回字段**：同 `getProvinces`，按层级从高到低排列。

### 4.4 getCascaderData(int $maxLevel = 3): array

获取级联选择器树形数据，适用于前端级联组件。

```php
$cascader = $regionService->getCascaderData(3);
// [
//     {
//         "value": "110000",
//         "label": "北京市",
//         "level": 1,
//         "children": [
//             {
//                 "value": "110100",
//                 "label": "北京市",
//                 "level": 2,
//                 "children": [
//                     { "value": "110101", "label": "东城区", "level": 3 },
//                     { "value": "110102", "label": "西城区", "level": 3 },
//                     ...
//                 ]
//             }
//         ]
//     },
//     ...
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $maxLevel | int | 最大层级，1-4，默认3（省市区），4为省市区街道 |

**返回结构**：

| 字段 | 类型 | 说明 |
|------|------|------|
| value | string | 区划代码 |
| label | string | 区划名称 |
| level | int | 层级 |
| children | array | 子级列表（叶子节点无此字段） |

### 4.5 getStats(): array

获取数据统计信息。

```php
$stats = $regionService->getStats();
// [
//     'province' => 34,
//     'city' => 393,
//     'district' => 3006,
//     'street' => 41693,
//     'total' => 45126,
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| province | int | 省级数量 |
| city | int | 市级数量 |
| district | int | 区县级数量 |
| street | int | 街道级数量 |
| total | int | 总数量 |

### 4.6 importData(): array

导入三级区划数据（省/市/区县）。采用差异化更新策略，根据 code 匹配：新增插入、改名/升级更新、撤销禁用。

```php
$result = $regionService->importData();
// [
//     'success' => true,
//     'message' => '三级数据更新完成，新增 2 条，更新 5 条，禁用 1 条',
//     'inserted' => 2,
//     'updated' => 5,
//     'disabled' => 1,
//     'stats' => ['province' => 34, 'city' => 393, ...],
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| inserted | int | 新增记录数 |
| updated | int | 更新记录数（改名、升级、重新启用） |
| disabled | int | 禁用记录数（源数据中已不存在） |

### 4.7 getStreetImportProvinces(): array

获取待导入街道数据的省份列表，从数据库读取所有省份，用于分步导入场景。

```php
$provinces = $regionService->getStreetImportProvinces();
// [
//     { id: 1, code: "110000", name: "北京市", file_count: 16, street_count: 0 },
//     { id: 2, code: "120000", name: "天津市", file_count: 16, street_count: 0 },
//     ...
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 省份ID |
| code | string | 省份代码 |
| name | string | 省份名称 |
| file_count | int | 该省区县数量（非文件数量） |
| street_count | int | 该省已导入街道数量 |

### 4.8 importStreetByProvince(string $provinceCode): array

按省份差异化更新街道/乡镇四级数据，每次调用处理一个省份，适用于 AJAX 分步导入。数据源来自民政部API，优先读取本地缓存文件。

```php
$result = $regionService->importStreetByProvince('110000');
// [
//     'success' => true,
//     'message' => '北京市 街道数据更新完成，新增 5 条，更新 2 条，禁用 1 条',
//     'inserted' => 5,
//     'updated' => 2,
//     'disabled' => 1,
//     'province' => '北京市',
//     'stats' => ['province' => 34, ...],
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $provinceCode | string | 省份代码，如 `110000`（北京市） |

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| inserted | int | 新增记录数 |
| updated | int | 更新记录数（改名、重新启用） |
| disabled | int | 禁用记录数（源数据中已不存在） |
| province | string | 省份名称 |

### 4.9 importStreetByDistrict(string $districtCode): array

按区县导入街道/乡镇四级数据，每次调用处理一个区县。数据源来自民政部API，优先读取本地缓存文件。适用于大省份分批导入，避免超时。

```php
$result = $regionService->importStreetByDistrict('110101');
// [
//     'success' => true,
//     'message' => '东城区 街道数据完成，新增 20 条',
//     'inserted' => 20,
//     'updated' => 0,
//     'disabled' => 0,
//     'province' => '东城区',
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $districtCode | string | 区县代码，如 `110101`（东城区） |

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| inserted | int | 新增记录数 |
| updated | int | 更新记录数（改名、重新启用） |
| disabled | int | 禁用记录数 |
| province | string | 区县名称 |

### 4.10 clearCache(): void

清除所有区划相关缓存。

```php
$regionService->clearCache();
```

### 4.11 importGeoData(): array

全量导入地理位置数据（城市和区县的经纬度信息）。数据源为本地Excel文件 `Data/地理位置编码.xlsx`。

```php
$result = $regionService->importGeoData();
// [
//     'success' => true,
//     'message' => '地理位置数据导入完成，更新 790 条，跳过 2610 条，失败 0 条',
//     'updated' => 790,
//     'skipped' => 2610,
//     'failed' => 0,
//     'stats' => ['province' => 34, 'city' => 333, ...],
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| updated | int | 更新记录数（经纬度有变化） |
| skipped | int | 跳过记录数（无变化或数据库中无对应记录） |
| failed | int | 失败记录数（数据格式错误） |

### 4.12 importGeoDataByProvince(string $provinceCode): array

按省份导入地理位置数据，每次调用处理一个省份，适用于 AJAX 分步导入。数据源为本地Excel文件 `Data/地理位置编码.xlsx`。

```php
$result = $regionService->importGeoDataByProvince('110000');
// [
//     'success' => true,
//     'message' => '北京市 地理位置数据导入完成',
//     'updated' => 17,
//     'skipped' => 0,
//     'failed' => 0,
//     'province' => '北京市',
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $provinceCode | string | 省份代码，如 `110000`（北京市） |

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| updated | int | 更新记录数（经纬度有变化） |
| skipped | int | 跳过记录数（无变化或数据库中无对应记录） |
| failed | int | 失败记录数 |
| province | string | 省份名称 |

### 4.13 getGeoProvinces(): array

获取Excel文件中所有省份列表，用于地理位置数据的分步导入场景。

```php
$provinces = $regionService->getGeoProvinces();
// [
//     { code: "110000", name: "北京市" },
//     { code: "120000", name: "天津市" },
//     ...
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| code | string | 省份代码 |
| name | string | 省份名称 |

### 4.14 getGeoStats(): array

获取地理位置数据统计信息。

```php
$stats = $regionService->getGeoStats();
// [
//     'total' => 3213,
//     'with_geo' => 3112,
//     'province_with_geo' => 0,
//     'city_with_geo' => 333,
//     'district_with_geo' => 2779,
// ]
```

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| total | int | 总记录数 |
| with_geo | int | 已有地理位置数据的记录数 |
| province_with_geo | int | 省级已有地理位置数据的记录数 |
| city_with_geo | int | 市级已有地理位置数据的记录数 |
| district_with_geo | int | 区县级已有地理位置数据的记录数 |

### 4.15 getGeojsonProvinces(): array

获取库中省级节点列表（过滤脏数据，仅保留 6 位数字编码），用于 GeoJSON 在线坐标更新的分步导入场景。

```php
$provinces = $regionService->getGeojsonProvinces();
```

### 4.16 importGeoDataFromGeojson(string $provinceCode): array

按省份从**阿里 DataV GeoJSON 数据源**（`geo.datav.aliyun.com`，免费、免 key、GCJ-02 坐标系）在线拉取最新坐标并更新库中记录。编码未命中时自动在同省范围内按名称兜底匹配（应对 DataV 旧编码与库中新编码的差异，如繁昌区 340211→340212）。

```php
$result = $regionService->importGeoDataFromGeojson('340000');
// [
//     'success' => true,
//     'message' => '安徽省 坐标导入完成（GeoJSON）',
//     'updated' => 5,
//     'skipped' => 120,
//     'missing' => 0,
//     'failed' => 0,
//     'province' => '安徽省',
// ]
```

**参数**：

| 参数 | 类型 | 说明 |
|------|------|------|
| $provinceCode | string | 省份代码，如 `340000`（安徽省） |

**返回字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| updated | int | 更新记录数（坐标有变化） |
| skipped | int | 跳过记录数（坐标无变化） |
| missing | int | 未匹配记录数（库中无对应编码且名称兜底未命中） |
| failed | int | 异常记录数（坐标数据格式错误） |
| province | string | 省份名称 |

> 覆盖说明：2023-24 最新设市县（米林市、错那市、和康县、和安县、新星市、白杨市、草湖市）DataV 暂未收录，无法通过该方式获取坐标。

### 4.17 RegionSnapshotService 快照导出服务

独立于 `RegionService` 的快照导出服务（`Services/RegionSnapshotService.php`），将库中全量区划导出为标准化 ZIP 快照包，供上传版本服务器（Versionsvr 应用「区划数据」服务）发布分发——各站点区划应用在线同步，使区划数据更新与本应用发版解耦。

**后台入口**：数据管理页「导出快照包」按钮（`GET /api/admin/cmspro/region/export-snapshot?version=可空`），版本号留空自动生成 `YYYYMMDD001`。

```php
use App\Apps\CmsproRegion\Services\RegionSnapshotService;

$snapshotService = app(RegionSnapshotService::class);
$snapshot = $snapshotService->export('20261106001');
// [
//     'file_path' => '...\storage\app\cmspro-region-data-20261106001.zip',
//     'file_name' => 'cmspro-region-data-20261106001.zip',
//     'version' => '20261106001',
//     'count' => 47362,            // 导出记录总数
//     'stats' => [...],            // 五级统计
//     'file_size' => 2103245,      // 快照包字节数
//     'checksum' => 'sha256...',   // 快照包 SHA-256 摘要
// ]
```

**导出包契约**（与版本服务器校验规则 `Versionsvr RegionDataService::validateRegions` 完全对齐）：

| 文件 | 内容 |
|------|------|
| `regions.json` | 全量记录数组 `[{code, name, level, parent_code, status, lon, lat}, ...]`，按 level + code 排序（父级先于子级）；顶级记录 `parent_code` 为 `'0'`；status 0（禁用）与 1（启用）全量导出；无坐标记录 `lon`/`lat` 为 null |
| `meta.json` | `{version, count, stats: {province, city, district, street, village}, generated_at（Y-m-d H:i:s）, format: "code,name,level,parent_code,status,lon,lat"}` |

**导出规则**：库中须已有省级（level=1）数据；单次上限 70 万条（与版本服务器一致）；版本号仅允许字母/数字/点/下划线/连字符（≤32 位）；导出流式分块（1000 条/块）避免大库内存溢出；调用方负责下载后删除快照包文件。

### 4.18 RegionDataSyncService 在线同步官方数据与快照包导入服务

独立于 `RegionService` 的在线同步服务（`Services/RegionDataSyncService.php`，消费端），从版本服务器（Versionsvr「区划数据」服务）在线同步官方区划数据，**全量替换**本地库——站点数据初始化/更新从「一键导入」（约 1 小时）降至 **1 分钟内**。

**后台入口**：数据管理页「在线同步官方数据」按钮，交互为「检查更新 → 弹窗选择同步级别并确认 → 同步（实时进度条）」，涉及三个接口：`GET /api/admin/cmspro/region/sync-check-update`（检查更新）+ `POST /api/admin/cmspro/region/sync-region-data`（执行同步，`version` 参数可指定历史版本回退、`max_level` 参数指定落库最大级别（仅 3/4/5，缺省按 5）、`progress_token` 参数启用进度上报）+ `GET /api/admin/cmspro/region/sync-progress`（轮询进度，前端 800ms/次）。弹窗以单选形式提供「同步至区县 / 街道 / 村社区」三档并标注各档累计条数，级别越浅落库量与耗时越少（详见 4.20 `RegionLevelService`）。

另有「**导入快照包**」按钮（站点间分享）：`POST /api/admin/cmspro/region/import-snapshot`（`file` 必填 zip ≤50MB、`progress_token` 可选），上传其他站点「导出快照包」生成的 ZIP，复用同一套契约校验与单事务全量替换落库链路，版本号取包内 `meta.json` 的 `version`（缺失回退 `snapshot-{YmdHis}`）；因上传包无可信校验和来源，不做 SHA-256 校验，须仅导入可信站点分享的包。

```php
use App\Apps\CmsproRegion\Services\RegionDataSyncService;

$syncService = app(RegionDataSyncService::class);

// 1. 检查远端最新版本（不落库）
$info = $syncService->checkUpdate();
// [
//     'available' => true,            // 版本服务器可用且有已发布版本
//     'version' => '20261106001',     // 远端最新版本
//     'region_count' => 47362,        // 远端记录总数
//     'stats' => [...],               // 五级统计
//     'local_version' => '',          // 本地已同步版本（空=从未同步）
//     'local_level' => 0,             // 本地已同步级别（0=未知，见 4.20）
//     'need_update' => true,          // 是否需要更新
//     ...
// ]

// 2. 在线同步（全量替换落库），null 同步最新版本，传版本号回退历史版本
//    第二参数为落库最大级别（1-5，缺省 5 五级全量）：仅同步省市区县传 3，含街道传 4
$result = $syncService->sync(null);
$result = $syncService->sync(null, 3);   // 只落库到区县级（约 3,239 条）
// [
//     'synced' => true,
//     'version' => '20261106001',
//     'count' => 47362,               // 同步记录数
//     'stats' => [...],
//     'duration' => 5.84,             // 耗时（秒）
// ]

// 3. 本地已同步版本号（读配置项，空字符串表示从未同步）
$version = $syncService->localVersion();

// 4. 进度上报（可选）：链式设置令牌后 sync 各阶段写缓存，另一请求轮询 getProgress 读取
$syncService->withProgressToken('sync_xxx')->sync(null);
$progress = $syncService->getProgress('sync_xxx');
// ['state' => 'running|done|error|idle', 'percent' => 45, 'stage' => '当前阶段文案', 'message' => '结果信息']
```

> 进度机制说明：长任务（`sync-region-data`）与轮询（`sync-progress`）在生产环境跑在不同 PHP-FPM 进程，进度经 **Cache 跨进程共享**（生产 `CACHE_STORE=redis`，测试 `array`）。控制器在长任务执行前调用 `session()->save()` 释放 session 写锁，避免轮询请求被阻塞。令牌为空时不上报（服务层直接调用场景），`getProgress` 对未知令牌返回 `idle` 态。

**同步链路**（五重安全网，任一步失败本地数据零影响）：

| 步骤 | 说明 |
|------|------|
| ① 检查更新 | `GET {apps.market.api_url}/api/versionsvr/region-data/latest`；未指定版本、本地版本与官方一致**且已同步级别不浅于本次请求级别**时跳过下载（级别更浅时不跳过，允许同版本补齐更深层级） |
| ② 下载校验 | 下载 `region-data/download/{version}`，SHA-256 与版本服务器声明值比对，不符拒绝 |
| ③ 解包 | 防 Zip Slip，兼容包内多一层包裹目录，读 regions.json |
| ④ 契约校验 | 与导出端/版本服务器同一套规则（必填/code 唯一≤12 位/level 1-5/parent 引用闭环/省级必含/上限 70 万），**流式执行**：校验结果写 JSONL 中间产物，**进事务前执行**，非法数据直接拒绝；**级别裁剪前置于规范化**——深于 `$maxLevel` 的记录直接跳过，既不进 code 校验集合（省内存）也不写 JSONL（省磁盘 IO） |
| ⑤ 单事务落库 | 全删（DELETE，MySQL 下 TRUNCATE 会隐式提交）→ 按 level 分层多遍扫描 JSONL 批量插入（`range(1, $maxLevel)` 逐层，2000 条/批，parent_code → parent_id，父级先于子级，层间 code 映射 `chunkById` 流式收集；仅末级以下无下级引用，故收集止于 `$maxLevel - 1`）→ 闭包表 SQL 化重建（`INSERT...SELECT` 逐层展开祖先链，深度由库中 `max(level)` 推导，级别裁剪后无需传参）→ 事务提交 |
| ⑥ 后处理 | 清缓存（`RegionService::clearCache()`）→ 记版本与级别（配置项 `app_cmspro_region_data_sync_version` / `app_cmspro_region_data_sync_level`，并联动写入 `app_cmspro_region_region_max_level` 使演示页选择器与数据深度一致；配置组由框架在应用安装/升级时落库，**组缺失时 `firstOrCreate` 自愈创建**，确保版本号一定写入）→ 清理旧包（保留最近 3 个） |

**性能基准**：

- 四级数据包（sqlite 内存库实测，49334 条四级区划 + 19.3 万条闭包记录）：契约校验 0.73s + 全量替换落库 5.11s ≈ **5.84s**。
- 五级村级数据包（MySQL 8 实测，584,265 条 + 284 万条闭包记录）：全链路流式化，512M 内存限制下**内存峰值仅 86MB**；流式校验约 3 分钟 + 插入 58 万条约 4 分钟 + 闭包重建约 1 分钟（SQL 化后 58 万节点），五级全量同步耗时约 10 分钟量级，建议低峰期执行。
- **按级别裁剪的落库量**（官方版本 `20261107002`，总数 638,772 条）：三级约 3,239 条、四级约 41,956 条、五级全量 638,772 条。只需省市区县或到街道的站点选择浅级别同步，落库量降低 2~3 个数量级，耗时与数据库写入压力（表体积、索引维护、闭包表行数）同步下降。

### 4.19 MapSettingService（地图配置解析）

独立于 `RegionService` 的地图配置服务（`Services/MapSettingService.php`，单例），为数据演示页解析「当前该用哪家地图、用哪个前端密钥、加载哪个官方 SDK」，并支持关联「地图」应用（CmsproMaps）复用其配置。

**配置来源**（配置项 `map_source`）：

| 取值 | 行为 |
|------|------|
| `none`（默认） | 不启用厂商地图，演示页使用 Leaflet + 高德瓦片底图 |
| `own` | 使用本应用「应用设置」页填写的厂商（`map_provider`）与前端密钥 |
| `maps` | 跟随 CmsproMaps 的 `provider` 与对应前端密钥；该应用未安装（`function_exists('cmspro_maps_config')` 为 false）或未配置时视为未配置，自动回退瓦片底图 |

**核心方法**：

| 方法 | 说明 |
|------|------|
| `get($key, $default)` | 读配置（敏感项自动解密，库中无记录时回退 `Config/region.php`），缓存键前缀 `cmspro_region:map_config:`、TTL 3600 秒 |
| `set(array $values)` | 按 `ALLOWED_KEYS` 白名单写入，密钥用 `Crypt::encryptString` 加密；**配置组缺失时抛 `RuntimeException`**，避免产生 `group_id=0` 的孤儿记录 |
| `resolveEffective()` | 返回 `configured/source/provider/key/sdk_url/coordinate_system/security_config/message`，**只含前端可用字段**，服务端密钥绝不输出 |
| `sdkUrl($provider, $key)` | 三家官方 JS API 地址（腾讯 `map.qq.com/api/gljs`、高德 `webapi.amap.com/maps`、百度 `api.map.baidu.com/api`）；百度固定带 `callback`，否则官方引导脚本走 `document.write` 被动态加载忽略 |
| `coordinateSystem($provider)` | 百度为 `bd09`，腾讯/高德为 `gcj02`（库内坐标统一 GCJ-02） |
| `configGroupReady()` / `mapsAppStatus()` | 供设置页判断是否展示「配置组未注册」提示、展示关联结果 |

**其他应用如需读取本应用地图配置**：`app(MapSettingService::class)->resolveEffective()`；仅当 `configured === true` 时 `sdk_url`、`key` 才有值。

> 配置组 `app_cmspro_region_map_settings` 由框架在安装/升级应用时按 `manifest.json` 写入。**从 v1.5.0 及更早版本升级的站点，须先在「应用管理」升级到应用 v1.6.0**，否则设置页提示升级，点击「保存设置」时由保存接口返回同一原因。

### 4.20 RegionLevelService（区划级别读写）

区划级别服务（`Services/RegionLevelService.php`，单例，`ServiceProvider` 注册），统一维护两组级别语义——「**区划显示级别**」（演示页选择器渲染深度，由「应用设置 → 区划级别设置」读写）与「**已同步数据级别**」（在线同步 / 导入快照包落库的最大级别，由同步链路自动写入）。

```php
use App\Apps\CmsproRegion\Services\RegionLevelService;

/** @var RegionLevelService $levels */
$levels = app(RegionLevelService::class);

$levels->maxLevel();          // 当前显示级别（int，1-5，默认 5）
$levels->setMaxLevel(3);      // 写入显示级别；非法值抛 RuntimeException（中文消息）
$levels->syncedLevel();       // 已同步级别（0 = 未知）
$levels->isValidLevel(3);     // bool：是否为 1-5 之间
$levels->levelName(3);        // '区县'
$levels->levelLabel(3);       // '3 级（至区县）'
$levels->saveSyncedLevel(3);  // 同步成功后调用：写 data_sync_level 并联动写 region_max_level
```

| 配置项（`app_cmspro_region_` 前缀） | 所属配置组 | 说明 |
|-----------------------------------|-----------|------|
| `region_max_level` | `region_settings` | 区划显示级别，1（省）~5（村/社区），默认 5 |
| `data_sync_level` | `sync_settings` | 已同步落库的最大级别，由 `saveSyncedLevel()` 写入 |

**关键行为**：

- **作用范围仅演示页选择器**：`RegionController::demo()` 用 `maxLevel()` 计算 `$canSelect` / `$showDistrict` / `$showStreet` / `$showVillage` / `$levelLabel`，各级下拉需同时满足「库中有该级数据」与「设置级别 ≥ 该级」才渲染；设置级别低于 3 时降级为省 / 市两级，并在状态卡片下方注明当前级别。**对外 API（`children` / `tree` / `list` / `path`）与 `RegionService` 契约零改动**，其他应用读取区划数据不受影响。
- **已同步级别缺失时回退库中真实层级**：`syncedLevel()` 在配置项不存在时取 `Region::max('level')`（查询异常按 0 处理），使本功能上线前已同步的老站点点击「在线同步」时能正确判定「已是最新」，不会重复全量导入 64 万条。
- **同步联动**：`saveSyncedLevel($level)` 同时把显示级别改写为 `$level`（快照包为五级全量导出，固定传 `RegionLevelService::MAX_LEVEL`），使选择器深度与实际数据一致；写入失败仅 `Log::warning`，不中断同步主流程。
- **配置组自愈**：写入时若 `region_settings` 组不存在，按 `manifest.json` 的 `config_groups` 声明 `firstOrCreate` 创建（标题「区划设置」），站点未升级也能保存；读取时组 / 配置项缺失一律回退默认值（显示级别 5、已同步级别 0）。
- **缓存**：读取走 `Cache::remember`（TTL 3600 秒，key `cmspro_region:level:{key}`），写入后立即 `Cache::forget`，保证设置页保存后演示页即时生效。

***

## 五、使用场景示例

### 5.1 用户地址选择

在用户注册或订单填写中，使用级联选择器让用户选择省市区：

```php
use App\Apps\CmsproRegion\Services\RegionService;

class OrderController extends Controller
{
    public function create()
    {
        $regionService = app(RegionService::class);
        $cascaderData = $regionService->getCascaderData(3);

        return view('order.create', compact('cascaderData'));
    }

    public function store(Request $request)
    {
        $regionService = app(RegionService::class);

        // 根据用户选择的区划代码获取完整路径
        $path = $regionService->getPath($request->input('district_code'));
        $address = $path->pluck('name')->implode('');

        // $address = "北京市东城区"
    }
}
```

### 5.2 前端级联选择器

通过API接口实现前端省市区联动选择：

```javascript
// 加载省份
$.get('/api/admin/cmspro/region/provinces', function(res) {
    renderOptions('#province', res.data);
});

// 省份变更时加载城市
$('#province').on('change', function() {
    var code = $(this).val();
    $.get('/api/admin/cmspro/region/children?code=' + code, function(res) {
        renderOptions('#city', res.data);
    });
});

// 城市变更时加载区县
$('#city').on('change', function() {
    var code = $(this).val();
    $.get('/api/admin/cmspro/region/children?code=' + code, function(res) {
        renderOptions('#district', res.data);
    });
});

// 区县变更时加载街道（需开启街道级数据）
$('#district').on('change', function() {
    var code = $(this).val();
    $.get('/api/admin/cmspro/region/children?code=' + code, function(res) {
        renderOptions('#street', res.data);
    });
});
```

### 5.3 按地域筛选数据

在业务数据表中存储区划代码，通过闭包表实现地域筛选：

```php
use App\Apps\CmsproRegion\Models\RegionClosure;

// 查询某省下所有订单（含下属市、区、街道）
$provinceId = Region::where('code', '440000')->value('id');

$descendantIds = RegionClosure::where('ancestor', $provinceId)
    ->pluck('descendant');

$orders = Order::whereIn('region_id', $descendantIds)->get();
```

### 5.4 通过钩子获取区划数据

在应用中通过钩子松耦合获取区划数据，无需直接引用 RegionService 类：

```php
// 在应用的 Hooks.php 中
public function register(HookManager $hooks): void
{
    // 监听某个业务事件时获取区划数据
    $hooks->registerAction('order.created', [$this, 'onOrderCreated'], 10, 'your_app');
}

public function onOrderCreated($order): void
{
    // 通过钩子获取区划路径
    $path = $this->hookManager->applyFilter('region.path', $order->region_code);
    $order->update(['region_text' => $path->pluck('name')->implode('')]);
}
```

### 5.5 区划代码验证

验证用户提交的区划代码是否有效：

```php
use App\Apps\CmsproRegion\Models\Region;

$code = $request->input('district_code');
$region = Region::where('code', $code)->where('status', 1)->first();

if (!$region) {
    return back()->withErrors(['district_code' => '无效的区划代码']);
}
```

***

## 六、数据库表结构

### 6.1 区划主表 `app_cmspro_region_region`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint unsigned | 主键ID |
| code | varchar(12) | 行政区划代码（唯一索引），省/市/区县6位，街道9位，村/社区12位 |
| name | varchar(50) | 区划名称 |
| level | tinyint unsigned | 层级：1-省 2-市 3-区县 4-街道 5-村/社区 |
| parent_id | bigint unsigned | 父级ID，顶级为0 |
| status | tinyint unsigned | 状态：0-禁用 1-启用 |
| lon | decimal(10,7) | 经度（范围：-180 ~ 180），城市和区县级别可用 |
| lat | decimal(10,7) | 纬度（范围：-90 ~ 90），城市和区县级别可用 |
| create_time | timestamp | 创建时间 |
| update_time | timestamp | 更新时间 |

### 6.2 闭包表 `app_cmspro_region_closure`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint unsigned | 主键ID |
| ancestor | bigint unsigned | 祖先节点ID |
| descendant | bigint unsigned | 后代节点ID |
| depth | int unsigned | 距离：0-自身 1-直接子级 2-孙级 |

**闭包表典型查询**：

```sql
-- 查询某节点所有后代
SELECT r.* FROM app_cmspro_region_closure c
JOIN app_cmspro_region_region r ON r.id = c.descendant
WHERE c.ancestor = ? AND c.depth > 0;

-- 查询某节点所有祖先（完整路径）
SELECT r.* FROM app_cmspro_region_closure c
JOIN app_cmspro_region_region r ON r.id = c.ancestor
WHERE c.descendant = ? AND c.depth > 0
ORDER BY c.depth DESC;
```

***

## 七、缓存说明

| 缓存键 | 内容 | 有效期 |
|--------|------|--------|
| `region:provinces` | 省份列表 | 24小时 |
| `region:v{version}:children:{code}` | 指定父级的子级列表（带版本号） | 24小时 |
| `region:v{version}:path:{code}` | 指定区划的完整路径（带版本号） | 24小时 |
| `region:cascader:{level}` | 级联选择器数据 | 24小时 |

缓存会在以下操作后自动清除：
- 三级数据导入/更新
- 街道数据按省份导入
- 手动清除缓存（后台操作或调用 `clearCache()` 方法）

> **动态缓存键版本化**：children/path 两类动态缓存键带版本号（`region:v{version}:...`）。`clearCache()` 清除固定键后自增缓存键 `region:version`（O(1)），旧版本命名空间的键立即不可读、由 TTL（24 小时）自然过期回收——避免村级数据量（58 万节点）下逐键删除的百万次缓存调用与 Eloquent 全量实例化内存开销。

***

## 八、注意事项

1. **区划代码规则**：省/市/区县为6位数字编码（后4位为`0000`是省级，后2位为`00`是市级，其余为区县级），街道/乡镇为9位数字编码，村/社区为12位数字编码
2. **直辖市特殊处理**：北京、天津、上海、重庆的区县直接挂在省级下（无中间市级），`getChildren('110000')` 直接返回区县列表
3. **应用依赖**：依赖行政区划服务的应用应在 `manifest.json` 中声明 `dependencies`
4. **缓存一致性**：直接操作数据库修改区划数据后，需调用 `clearCache()` 清除缓存；街道数据缓存文件修改后需重新导入
5. **差异化更新**：导入操作基于 `code` 匹配，新增插入、改名/升级更新、撤销禁用，保留已有 ID 不变
6. **街道数据更新**：`importStreetByProvince()` 按省份差异化更新，支持改名、升级、撤销等场景；数据源来自民政部API，自动处理分页
7. **服务单例**：`RegionService` 注册为单例，同一请求中多次调用共享实例
8. **层级限制**：`getCascaderData()` 的 `maxLevel` 参数最大为5（村/社区级），API 接口层超过5按5处理
9. **数据更新**：区划数据来自中国国家地名信息库（[https://dmfw.mca.gov.cn/XzqhVersionPublish.html](https://dmfw.mca.gov.cn/XzqhVersionPublish.html)），点击「更新数据」按钮可对比API最新数据与本地数据的差异并更新；`Data/list.json` 和 `Data/code/` 目录为本地缓存文件
10. **安装与导入分离**：安装应用仅创建数据库表，需在后台手动导入数据，避免安装超时
11. **API调用频率**：街道数据导入时每处理一个区县暂停100ms，避免请求过快导致API限流
12. **地理位置数据**：经纬度信息仅在城市和区县级别可用，街道级别暂不支持；数据源为本地Excel文件 `Data/地理位置编码.xlsx`，需手动点击「导入地理位置」按钮导入
13. **快照导出契约稳定性**：导出包内 `regions.json` / `meta.json` 的文件名、字段集与排序规则为版本服务器（Versionsvr「区划数据」服务）与消费端（各站点区划应用在线同步）共同依赖的分发契约，调整任何一项前须同步评估版本服务器校验规则与各站点消费端兼容性，禁止单方面变更
14. **在线同步为全量替换**：`RegionDataSyncService::sync()` 会全量替换本地区划数据（本地手工修改会被覆盖），仅适用于「以官方数据为准」的场景；需保留本地定制的站点请使用导入链路并自行维护差异；指定 `max_level` 按级别同步时，**深于该级别的既有数据（如已同步的村/社区）也会一并清除**
15. **导入快照包为不可信上传**：`importFromSnapshot()` 接受任意来源的 ZIP 上传，虽经防 Zip Slip 与契约校验（非法数据进事务前拒绝、落库单事务回滚），但**不做 SHA-256 校验**（无可信校验和来源），仅限后台管理员使用且应只导入可信站点分享的包
15. **同步版本配置组依赖**：已同步版本号记录依赖配置组「数据同步设置」（`app_cmspro_region_sync_settings`）落库，该组由框架在应用**安装/升级**时按 manifest `config_groups` 声明创建——旧站点首次使用在线同步前需在后台「应用管理」对本应用执行一次升级，否则版本号记录不生效（同步本身不受影响）。**v1.7.0 起已改为写入时 `firstOrCreate` 自愈建组**，未升级站点同样能记录版本；同版本新增的「区划设置」组（`app_cmspro_region_region_settings`，承载区划显示级别）也采用同样的自愈策略
16. **本地回填的村/社区会被全量替换抹掉**：迁移 `2026_11_09_000001_backfill_unmatched_villages` 写入的 14,291 个村/社区（数据源 `Data/backfill_unmatched_villages_20261109.csv`，兼作本批独立备份）属于**本地补齐数据**，官方数据包与快照包内均无这些记录——执行「在线同步官方数据」或「导入快照包」后本批数据会随全量替换一并消失。恢复方式：后台「应用管理」对本应用执行一次升级（触发 `runMigrations()`），或删除 `migrations` 表中该条记录后升级；迁移按 `code` 幂等（`uk_code` 已存在的行自动跳过），可安全重跑。回滚执行迁移 `down()`（按 CSV 内 code 集合删主表 level=5 行与闭包表对应 descendant 行，删叶子节点后闭包天然一致，无需全量重建）。**该 CSV 未纳入 git 跟踪、也不随发布包分发**，只存在于执行回填的本地站点；其他站点文件缺失时 `planRows()` 返回空数组，`up()`/`down()` 静默跳过（1.12.4 修复：此前直接 `fopen()`，文件不存在触发的 E_WARNING 被框架错误处理器转成 `ErrorException`，使升级中断并报「升级失败：fopen(...): Failed to open stream」）
17. **DataV 坐标订正（1.12.5）与在线同步的相互作用需注意，两条路径结果不同**：迁移 `2026_11_10_000001_align_level123_coordinate_with_datav` 订正的 565 条 level1-3 坐标（数据源 `Data/region_coordinate_datav_align_20261110.csv`）在「在线同步官方数据」或「导入快照包」后同样会消失（`applyGeojsonCoordinate()` 与 `importSnapshot()` 均重置 `lon`/`lat`），但恢复方式与最终结果不一致：① 走在线同步后再触发「在线更新坐标(GeoJSON)」，坐标由 DataV 重建（`center` 优先、`centroid` 兜底），level1-3 会自然回到官方值、与本次订正口径一致，**但 `460302` 西沙区会被写成 DataV 的不可靠值 `112.891018,9.543575`**（本次特意保留库内正确的永兴岛坐标 `112.334,16.8327`，同步重建反使其变错），`460303` 南沙区与 8 条 DataV 未收录的新设区划同样不会被修好，仍需人工定值；② 走导入快照包路径（不触发坐标重建）则坐标全为 `NULL`，需升级重跑本迁移恢复。**与第 16 条的关键差异**：本次 CSV **已纳入 git 跟踪并随发布包分发**（它同时是 `down()` 精确还原原值的唯一依据，删了就无法回滚），不像 1.12.1 的回填 CSV 只存在于本地站点；因此不得把它加入 `manifest.json` 的 `runtime_files`（`region_data` 的 `pattern` 为 `["list.json", "code/*.json"]`，天然不匹配本 CSV，保持现状即可，否则会被「清理运行时文件」误删）。迁移按 `code` 幂等（当前值与目标值之差 <1e-7 即跳过），可安全重跑

***

## 九、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 1.0.0 | 2026-06-20 | 开发团队 | 初始版本：省市区三级数据、闭包表架构、缓存机制、数据导入 |
| 1.1.0 | 2026-06-22 | 开发团队 | 新增街道/乡镇四级数据支持、按省份分步导入、街道数据缓存机制 |
| 1.2.0 | 2026-06-24 | 开发团队 | 新增民政部 API 差异化对比更新、地理位置数据导入（经纬度） |
| 1.3.0 | 2026-06-25 | 开发团队 | 补充按区县导入街道数据、按省份导入地理位置数据等分步导入能力 |
| 1.4.0 | 2026-08-12 | 开发团队 | 文档审查更新：API 接口列表补充按省份导入三级数据（import-by-province）、区划列表（list）、缓存清除（clear-cache）、数据缓存文件下载/清理（download-districts/download-district-data/download-list-json/clear-data-files）、GitHub 数据源检测（check-github）、闭包表重建（rebuild-closure）、民政部全量导入（import-from-mca）、list.json 省份列表（list-json-provinces）等接口 |
| 1.5.0 | 2026-09-13 | 开发团队 | 新增「一键数据更新」：后台数据管理页单按钮串联四阶段在线更新流程（① 三级区划：民政部 API 逐省分步 → ② 港澳台补全：阿里 DataV，失败不中止 → ③ 街道数据：逐区县民政部 API（本地缓存优先），失败区县自动追加一轮重试 → ④ 最新坐标：阿里 DataV GeoJSON 逐省），统一进度条按阶段分段映射（0~5%/5~30%/30~85%/85~99%），执行期间按钮互斥；官方数据包（Data/ 目录）作为新装基线集合数据 |
| 1.6.0 | 2026-11-06 | 开发团队 | 新增 `RegionSnapshotService` 快照导出服务（4.17 节）与「导出快照包」后台按钮：将库中全量区划导出为标准化 ZIP 快照包（regions.json + meta.json），供上传版本服务器（Versionsvr「区划数据」服务）发布分发；导出契约与版本服务器校验规则完全对齐（level+code 排序、parent_id→parent_code 转换、顶级 parent_code='0'、status 0/1 全量、无坐标 null、meta 含 version/count/stats/generated_at/format）；新增权限 `cmspro.region.export` |
| 1.7.0 | 2026-11-06 | 开发团队 | 新增 `RegionDataSyncService` 在线同步官方数据服务（4.18 节，消费端）与「在线同步官方数据」后台按钮：从版本服务器在线同步官方区划数据，检查更新 → 下载 SHA-256 校验 → 解包（防 Zip Slip）→ 契约校验 → 单事务全量替换落库（全删 → 按 level 分层 chunk 批量插入 → 闭包表重建）→ 清缓存 → 记版本；五重安全网（校验和/前置契约/事务回滚/版本回退/重复跳过），任一步失败本地数据零影响；新增配置组「数据同步设置」；站点数据初始化从约 1 小时降至 1 分钟内（49334 条实测 5.84s） |
| 1.7.2 | 2026-11-06 | 开发团队 | 在线同步体验修复（4.18 节，应用版本不变）：① `saveVersion()` 配置组缺失时 `firstOrCreate` 自愈建组，修复已同步但前端仍显示「未同步」；② 新增进度上报机制——`withProgressToken()` 链式设置令牌后 sync 各阶段经 Cache 写进度（生产 redis 跨进程共享），`getProgress()` 供 `GET sync-progress` 轮询，前端实时进度条替代静态提示；控制器长任务前 `session()->save()` 释放 session 锁避免轮询阻塞 |
| 1.8.0 | 2026-11-06 | 开发团队 | 新增 `importFromSnapshot()` 快照包导入（4.18 节，站点间分享）：接收上传 ZIP（`POST import-snapshot`，zip ≤50MB），复用在线同步的解包（防 Zip Slip）/契约校验/单事务全量替换落库/进度上报链路；版本号取包内 meta.json（缺失回退 `snapshot-{YmdHis}`）；不做 SHA-256 校验（上传包无可信校验和来源），注意事项新增第 15 条「导入快照包为不可信上传」 |
| 1.9.0 | 2026-11-07 | 开发团队 | 新增**行政村/社区五级数据**（应用 v1.5.0）：① code 字段扩容迁移 varchar(9)→varchar(12)；② 导出/导入/在线同步契约支持五级（level 1-5、code ≤12 位、上限放宽至 60 万条、stats 增 village，与版本服务器同步）；③ 同步链路流式化——数据包解包转存 regions.json、契约校验流式写 JSONL 中间产物、按层级分遍扫描批量插入（2000 条/批）、层间 code 映射 `chunkById` 流式收集，58.4 万条五级全量同步内存峰值 86MB（512M 限制，MySQL 实测）；④ 闭包表重建 SQL 化（`INSERT...SELECT` 逐层展开，58 万节点约 31 分钟 → 约 1 分钟）；⑤ `clearCache()` 版本号命名空间失效（七、缓存说明），children/path 动态缓存键带版本号、旧键由 TTL 自然过期 |
| 1.10.0 | 2026-11-07 | 开发团队 | 新增 `MapSettingService` 地图配置服务（4.19 节）与后台「应用设置」页（`/admin/cmspro/region/setting`，权限 `cmspro.region.setting.manage`）：三种配置来源 `none`/`own`/`maps`（可关联 CmsproMaps 复用其 `provider` 与前端密钥），四项前端密钥 `Crypt` 加密落库，manifest 新增配置组 `map_settings`；数据演示页地图层重构为厂商适配器（百度 BMapGL / 高德 AMap 2.0 / 腾讯 TMap GL），坐标系按厂商转换、SDK 加载失败自动回退 Leaflet + 高德瓦片（对应应用 v1.6.0） |
| 1.11.0 | 2026-11-08 | 开发团队 | 新增 `RegionLevelService` 区划级别服务（4.20 节，对应应用 v1.7.0）：① 在线同步支持**按级别裁剪落库**——`sync($version, $maxLevel)` 第二参数指定最大级别，级别裁剪前置到契约校验阶段（深于所选级别的记录不进 code 校验集合、不写 JSONL、不落库），`importRegionRows` 按 `range(1, $maxLevel)` 分遍插入，闭包重建深度仍由库中 `max(level)` 推导；后台弹窗改为「版本信息 + 同步级别单选（3 区县 / 4 街道 / 5 村社区，标注累计条数，默认选中已同步级别）」，接口 `max_level` 仅接受 3/4/5，浅级别把落库量从 63.8 万条降到 3 千 / 4.2 万条量级；② 重复保护改为「版本相同**且已同步级别不浅于请求级别**才跳过」，`checkUpdate()` 增返 `local_level`，支持同版本补齐更深层级；③ 新增配置项 `data_sync_level`（已同步级别，缺失时回退 `Region::max('level')`，避免老站点重复全量导入）与 `region_max_level`（区划显示级别 1~5 默认 5），新建配置组 `region_settings`（写入时 `firstOrCreate` 自愈），同步 / 导入快照包成功后自动联动写入显示级别；④ 「应用设置」页新增「区划级别设置」卡片，与地图配置共用同一表单与保存接口；⑤ 数据演示页各级下拉改为「库中有该级数据 && 设置级别 ≥ 该级」才渲染，级别低于 3 时降级为省 / 市两级并提示当前设置——**对外 API 与 `RegionService` 契约零改动** |
| 1.12.0 | 2026-11-09 | 开发团队 | **数据回填**（应用版本不变，纯 DML 无代码逻辑改动）：新增迁移 `V2026110901`（`Migrations/2026_11_09_000001_backfill_unmatched_villages.php`）+ 数据源 `Data/backfill_unmatched_villages_20261109.csv`，回填 14,291 个「统计区划代码存在、本地库找不到上级乡镇」的村/社区——按 2,939 个街道节点逐项人工查证真实归属后写入 `parent_id`，村 code 保留原 12 位统计代码不重编码；对账闭合 14,425 = 14,291 写入 + 9 同名跳过 + 125 真实缺口（缺口村另立清单待人工处置，本批不写）。落位分布：街道下 13,672 / 兵团师市 L3 662 / 兵团师市 L4 45 / 代码前缀回退 27 / 兵团无师市按地理区县 10 / 挂靠回退 9；L3 落点村直接挂区县跳过 L4，兵团团场按行政隶属挂师市。写入后计数：level=5 由 606,144 增至 **620,435**，总记录 648,102 → **662,393**，闭包表 3,154,717 → **3,215,228**（+60,511，平均每村 4.23 行），L3/L4 零变化；只读验证 14 项全 PASS（行数与层级、code 全落库且 level/name/parent_id 逐字段匹配、`uk_code` 无重复、孤儿节点 0、闭包 self 行与 depth=1 父链各 14,291/14,291、父链均可达 L1、闭包增量合理）。注意事项新增第 16 条「本地回填的村/社区会被全量替换抹掉」 |
| 1.12.1 | 2026-11-10 | 开发团队 | **村/社区坐标手工补全 + 同名重复记录合并**（应用版本不变，纯 DML 无代码逻辑改动，未新增迁移）：为 65 个村/社区补写 GCJ-02 坐标——湖北仙桃市 7 条、天门市 3 条，新疆石河子市 8 条、五家渠市 8 条、铁门关市 36 条、图木舒克市 2 条、阿拉尔市 1 条。这批村在后台「在线更新坐标(GeoJSON)」下取不到值：阿里 DataV 仅收录到区县级，村级 12 位编码请求无数据（与数据演示页「行政区划边界」无村级边界同源），故按人工整理结果直接落库。写库均带 `whereNull('lon')` 护栏，只补空值、不覆盖既有坐标，实测覆盖 0 条。同时按人工裁决合并石河子市 3 条同名重复记录：「十九连生活区」保留 `652324521517`、删除 `652324522518` / `652324524521`，「砖厂生活区」保留 `650203530514`、删除 `652324522523`——删除同步清理闭包表关系行 9 条（每条 3 行：self depth=0、师市 depth=1、省 depth=2），删除前 6 项预检（存在 / code+name 相符 / level=5 / 无坐标 / 无子级 / 保留记录 lon 为空），删后全库孤儿节点 0。变更后计数：level=5 由 620,435 降至 **620,432**、总记录 662,393 → **662,390**、闭包表 3,215,228 → **3,215,219**，L1~L4 零变化。遗留：铁门关市仍有 2 条村无坐标（`652829501504` 六连队、`652829501506` 二连队），人工清单未提供。**风险同 1.12.0**：执行 4.18「在线同步官方数据」或「导入快照包」为全量替换，这批坐标会被抹掉、被删的 3 条同名记录会随官方包重新出现（见注意事项第 16 条） |
| 1.12.2 | 2026-11-10 | 开发团队 | **同父级同名重复记录合并**（应用版本不变，纯 DML 无代码逻辑改动，未新增迁移）：排查口径 `GROUP BY (parent_id, name) HAVING COUNT(*) > 1`，按 level 分别执行——level=3（同一市下同名区/县）0 组、level=4（同一区县下同名街道/乡镇）0 组、level=5（同一街道/乡镇或直挂区县下同名村/社区）**322 组 / 1,098 条**。用户裁决全量合并，保留策略沿用 `code` 升序第 1 条，删除 776 条主表记录 + 2,709 行闭包关系。**分类判据**：以「code 前 9 位乡镇段是否一致」+「组内坐标两两最大 Haversine 距离（阈值 2km）」分为 A 同段坐标重合 47 组（待删 47）/ B 跨段坐标重合 273 组（待删 727）/ C 坐标相距远 **0 组** / D 组内坐标缺失 2 组（待删 2）；实测组内最大距离 <0.2km 者 320 组、其余坐标完全一致，C 类为 0 故不存在「异地同名村被误删」风险。B 类中 270 组父级为 level=3（兵团师市 `6590xx`、省直辖县级市 `4290xx` 等「村直接挂区县、跳过 L4」形态，闭包仅 3 行）。**执行链路**：实时重新分组并与预期（322 组 / 776 条）对账，不符即中止 → 六项安全预检（待删条无子级、status=1、无「保留条缺坐标而待删条有坐标」、祖先链完整等）→ 落盘备份 JSON + 可执行回滚 SQL → 单事务内「坐标回填（带 `whereNull` 护栏，实回填 0 条）→ 按祖先链收窄 `ancestor` 删闭包行（实删行数与链长不符即抛异常回滚）→ 分批 500 删主表」→ `clearCache()` → 六项回读验证。**闭包删除写法（索引陷阱）**：`uk_ancestor_descendant` 最左前缀是 `ancestor`，直接按 `descendant` 过滤会全表扫 321 万行，故先沿主表 `parent_id` 收集祖先链（**必须在删主表前收集**）再 `whereIn('ancestor', $chain)->where('descendant', $id)`；本次祖先链长分布 `3:445 / 4:281 / 5:50`（对应兵团直挂 / 直辖市 / 普通省份）。**验证结果**：L5 同父级同名剩余 0 组、主表孤儿 0 条、闭包 descendant 侧与 ancestor 侧残留均 0 行、322 条保留记录全在库且其中无坐标者 0 条；计数 level=5 620,432 → **619,656**、总记录 662,390 → **661,614**、闭包表 3,215,219 → **3,212,510**，L1~L4 零变化（34 / 333 / 2,874 / 38,717）。顺带清零 1.12.1 遗留：铁门关市 `652829501504` / `652829501506` 作为 D 类重复被删，保留条已有坐标。备份产物 `backup_dup_merge_20261110_095109.json` 与 `rollback_dup_merge_20261110_095109.sql` 存于临时工作目录，未纳入应用目录。**风险同 1.12.0**：执行 4.18「在线同步官方数据」或「导入快照包」为全量替换，这 776 条同名记录会随官方包重新出现，需重跑合并（见注意事项第 16 条） |
| 1.12.3 | 2026-11-10 | 开发团队 | **数据管理页双击行查看区划完整详情**（应用版本不变，仅 `Views/Admin/index.blade.php` 单文件改动，Controller / Service / 路由 / 迁移 / 数据库零改动）：双击列表行弹出 `layer.open` 详情层，展示层级路径 + `region` 表全部字段（id / code / name / level / parent_id / status / lon / lat / create_time / update_time）+ 直接子级数量。**接口复用而非新增**——`list` 接口 `paginate()->toArray()['data']` 已返回模型全字段（`$casts` 使 `lon`/`lat` 为 float、时间为 `Y-m-d H:i:s`），故详情只需并发调用既有 `GET /api/admin/cmspro/region/path`（闭包表祖先链，`depth>0` 按 level 升序 + 末位追加自身）与 `GET /api/admin/cmspro/region/children`（`parent_id` 直接子级，取数组长度即数量）；`level>=5` 为最末级不发 `children` 请求，直接按空集处理。**layui 契约**：`table` 模块未提供行双击事件，在 `table.render` 的 `done` 回调内自行绑定——`$('#regionTable').next('.layui-table-view').find('.layui-table-body tbody tr')` 上按 `$(this).data('index')` 从 `table.cache['regionTable'][index]` 取行数据（已核实 `layui.js` 中 `tr` 由 `data-index="'+e+'"` 拼接、`k.cache[e]` 即行数据源，`.next('.layui-table-view')` 定位方式与框架 `resources/views/admin/menu/index.blade.php` 一致）；事件带 `dblclick.regionDetail` 命名空间并先 `off` 再 `on`，避免重载重复绑定；无数据时占位 `tr` 无 `data-index`，取值为 `undefined` 故不会误弹。**容错**：两个请求用 `pending` 计数 + `.always()` 收敛，任一失败仍弹层，路径显示「未获取到层级路径」、子级显示「获取失败」，最末级子级显示「无（最末级或暂无下级数据）」；所有取自数据库的名称/编码经 `escapeDetailHtml` 转义后再拼接，日期直接展示不做二次 `format()`。**验证**：Blade 编译产物 `php -l` 通过、内联脚本 `node --check` 通过；把详情渲染函数从编译产物中抽出、注入真实库数据在 node 中执行，村级样本（多福巷社区居委会，路径 5 级、子级 0）与直辖市顶级样本（北京市，路径仅 4 级因无 level=2、子级 16）输出的字段值、上级区划、占位符均逐项核对正确 |
| 1.12.4 | 2026-11-10 | 开发团队 | 修复**未持有回填 CSV 的站点升级中断**（应用版本不变，仅 `Migrations/2026_11_09_000001_backfill_unmatched_villages.php` 单文件改动）：远程站点在「应用管理 → 升级」时报 `升级失败：fopen(.../Data/backfill_unmatched_villages_20261109.csv): Failed to open stream: No such file or directory`（框架 `AppInstallerService::executeUpgrade()` 返回 50010）。**根因**：`planRows()` 直接 `fopen($path,'rb')`，文件不存在时 PHP 抛 E_WARNING，被 Laravel `HandleExceptions` 的 `set_error_handler` 转成 `ErrorException` 上抛，注释里声明的「文件缺失静默跳过」形同虚设——`$handle === false` 分支永远走不到；而该 CSV 为本地一次性数据源，`git ls-files` / `git log` 均无记录（未跟踪、未分发），故任何未持有该文件的站点升级必然失败。**修复**：`fopen` 前先 `is_file()` + `is_readable()` 判断，缺失即 `return []`，`up()` 记 info 日志后跳过、`down()` 空 code 集合无操作。**验证**：临时脚本注册「warning→ErrorException」错误处理器模拟框架行为，对照组裸 `fopen` 复现原始报错文案，修复后反射调用 `planRows()` 正常返回 0 行、无异常。**现场影响**：`runMigrations()` 位于版本号写库事务之前，升级失败时应用代码已被解压覆盖但数据库 `apps.version` 仍为旧值、该迁移未记入 `migrations` 表，同步本修复后重新点「升级」即可继续，无需手工清理 |
| 1.12.5 | 2026-11-10 | 开发团队 | **level1-3 坐标全量对齐阿里 DataV 官方中心点**（应用 v1.7.3，新增 `Migrations/2026_11_10_000001_align_level123_coordinate_with_datav.php`（V2026111001，DML）与数据源 `Data/region_coordinate_datav_align_20261110.csv`；`Services/`、`Controllers/`、视图与接口零改动）。**问题**：库内 level1-3 存在批量错值，成因是 `updateBaiduCoordinate()` 调百度 JSAPI 做地址正解析时返回错误结果并落库（香港 `810000` 及其南区 / 东区 / 北区四条共用河北坐标 `116.653993,40.128566`，偏差约 2000km）。**取数**：以只读临时脚本反射调用 `RegionService::fetchGeojson()`（protected），分三阶段拉取——省级 `{code}.json`、市级与区县级 `{code}_full.json`（下级取 `features[].properties`），仅落盘 `adcode / name / level / center / centroid / parent` 精简字段、不存几何，共 396 文件 / 477KB，支持断点续跑；`710000_full`（台湾无下级数据）与海南 `469xxx`、兵团 `659xxx`、嘉峪关 `620200` 的 `_full` 返回 404 均属预期（省直辖县级市本身无下级，其自身坐标已从省级 `_full` 取得）。**比对**：Haversine 球面距离分档（<1 / 1~5 / 5~100 / >100km），`adcode` 精确匹配优先、同省同名兜底；DataV 与库内同为 GCJ-02（依据既有 `applyGeojsonCoordinate()` 直接写库不做转换），无需坐标系转换。**导出**：阈值 1km，硬编码排除 `460302` / `460303`（DataV 把两区标为同一点 `112.891018,9.543575`，官方数据在此不可靠），导出 565 条（L1 26 / L2 209 / L3 330），9 列带 UTF-8 BOM 便于 Excel 审阅。**迁移实现**：`CASE code WHEN ... THEN ... ELSE ... END` + `WHERE code IN (...)` 批量 UPDATE（分批 200 行、IN 分片 500、单事务），规避循环内单条 SQL；**明确不用 `upsert()`**——`code` 不存在时它会 INSERT 出缺 `name` / `level` / `parent_id` 的残缺行；`up()` / `down()` 共用 `applyCoordinate(string $direction)`，`down()` 以 CSV 的原经度 / 原纬度精确还原；幂等判定 `abs(cur - target) < 1e-7`（对齐 `decimal(10,7)` 精度），当前值已等于目标值即跳过；沿用 1.12.4 教训，`fopen` 前先 `is_file()` + `is_readable()`，CSV 缺失静默跳过；`testing` 环境直接 `return`（避免污染用例的记录数断言）；收尾调 `RegionService::clearCache()`。**验证链**：`php -l` → 预检脚本反射调用迁移的 `planRows()`（确保预检与真实执行走同一数据路径）做 6 项校验，异常计数全 0 → `migrate --pretend` 确认仅本迁移 pending → 正式执行 173ms DONE → 重跑比对脚本：省级 34/34、市级 333/333 与官方值完全一致、区县级 2,864 条一致，偏差 ≥5km 由 142 条降至 2 条（即有意排除的三沙）→ 应用测试 115 passed / 676 assertions（迁移前后各跑一次，基线未破坏）→ **回滚实测闭环** A（新值）→ `rollback` DONE 246.99ms → C（精确还原原值，`810000` 回到 `116.6539930,40.1285660`、`110000` 回到 `116.7245020,39.9050230`）→ `migrate` DONE 150.64ms → E（恢复新值）；`460302` 的 `update_time` 全程停在 `2026-11-08 11:48:44`，证明排除生效。**版本递增说明**：纯数据订正但仍升 1.7.2 → 1.7.3，因 `AppInstallerService::upgrade()` 校验 `version_compare($targetVersion, $app->version, '<=')` → 50011「目标版本必须高于当前版本」，版本号不变则其他站点无法经在线升级触发本迁移（上传包升级走 `executeUpgrade()` 无此限制）。**纠正上一轮误判**：此前用父子距离法诊断曾报「香港 18 个区全错」，DataV 比对证明实际仅 5 条错（省级 + 南区 / 东区 / 北区 / 中西区），其余 13 个区系被错误父级连坐——印证「必须自顶向下修」；父级订正后以 `ST_Distance_Sphere` 重跑 L4/L5，超阈值数由 167 / 5,220 降至 115 / 5,198（消除 74 条连坐误判），该两级因 DataV 无数据不在本次范围，其中 >300km 的确定错 235 条待另行处置。风险与恢复见「注意事项」第 17 条 |
