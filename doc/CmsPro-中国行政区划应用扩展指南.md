# CmsPro 中国行政区划应用扩展指南

> 版本：1.5.0 | 更新日期：2026-09-13
> 适用系统：CmsPro v5.0.0+
> 应用ID：`cmspro.region`

***

## 一、应用概述

中国行政区划应用基于（[中国国家地名信息库](https://dmfw.mca.gov.cn/XzqhVersionPublish.html)）提供省、市、区县三级联动查询（默认）和街道/乡镇四级数据（可选），采用单表+闭包表存储架构，支持高效的层级关系查询。核心服务注册为Laravel单例，可被任何应用通过依赖注入、钩子或API三种方式引用。

### 核心特性

- **三级联动**：默认支持省/市/区县三级区划数据（约3200+条，含港澳台）
- **四级可选**：可配置开启街道/乡镇级数据（约4万+条），按省份分步导入，避免超时
- **闭包表架构**：单表存储+闭包表加速，支持高效的层级路径查询
- **三种引用方式**：依赖注入、Filter钩子、API接口，灵活适配不同场景
- **缓存机制**：查询结果自动缓存24小时，数据更新时自动清除；街道数据支持本地文件缓存
- **数据管理**：后台提供数据导入/更新、街道分步导入、缓存清除、级联预览、数据对比更新等功能
- **地理位置数据**：支持导入城市和区县的经纬度信息，便于地图定位和距离计算

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

安装完成后，在后台「行政区划 → 数据管理」页面中，点击「导入三级数据」按钮导入省/市/区县数据。由于三级数据有3200+条（含港澳台），系统采用 **按省份分步导入** 策略：

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
| code | varchar(9) | 行政区划代码（唯一索引），省/市/区县6位，街道9位 |
| name | varchar(50) | 区划名称 |
| level | tinyint unsigned | 层级：1-省 2-市 3-区县 4-街道 |
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
| `region:children:{code}` | 指定父级的子级列表 | 24小时 |
| `region:path:{code}` | 指定区划的完整路径 | 24小时 |
| `region:cascader:{level}` | 级联选择器数据 | 24小时 |

缓存会在以下操作后自动清除：
- 三级数据导入/更新
- 街道数据按省份导入
- 手动清除缓存（后台操作或调用 `clearCache()` 方法）

***

## 八、注意事项

1. **区划代码规则**：省/市/区县为6位数字编码（后4位为`0000`是省级，后2位为`00`是市级，其余为区县级），街道/乡镇为9位数字编码
2. **直辖市特殊处理**：北京、天津、上海、重庆的区县直接挂在省级下（无中间市级），`getChildren('110000')` 直接返回区县列表
3. **应用依赖**：依赖行政区划服务的应用应在 `manifest.json` 中声明 `dependencies`
4. **缓存一致性**：直接操作数据库修改区划数据后，需调用 `clearCache()` 清除缓存；街道数据缓存文件修改后需重新导入
5. **差异化更新**：导入操作基于 `code` 匹配，新增插入、改名/升级更新、撤销禁用，保留已有 ID 不变
6. **街道数据更新**：`importStreetByProvince()` 按省份差异化更新，支持改名、升级、撤销等场景；数据源来自民政部API，自动处理分页
7. **服务单例**：`RegionService` 注册为单例，同一请求中多次调用共享实例
8. **层级限制**：`getCascaderData()` 的 `maxLevel` 参数最大为4，超过4按4处理
9. **数据更新**：区划数据来自中国国家地名信息库（[https://dmfw.mca.gov.cn/XzqhVersionPublish.html](https://dmfw.mca.gov.cn/XzqhVersionPublish.html)），点击「更新数据」按钮可对比API最新数据与本地数据的差异并更新；`Data/list.json` 和 `Data/code/` 目录为本地缓存文件
10. **安装与导入分离**：安装应用仅创建数据库表，需在后台手动导入数据，避免安装超时
11. **API调用频率**：街道数据导入时每处理一个区县暂停100ms，避免请求过快导致API限流
12. **地理位置数据**：经纬度信息仅在城市和区县级别可用，街道级别暂不支持；数据源为本地Excel文件 `Data/地理位置编码.xlsx`，需手动点击「导入地理位置」按钮导入

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
