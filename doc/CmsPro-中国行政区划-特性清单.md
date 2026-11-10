# CmsPro-中国行政区划-特性清单

> 版本：1.12.0 | 更新日期：2026-11-08
> 应用版本：1.7.0 | 应用 ID：cmspro.region

---

## 一、应用体系架构

| 特性 | 说明 | 实现位置 |
|------|------|----------|
| 目录结构 | 应用级代码位于 `app/Apps/CmsproRegion/` | `app/Apps/CmsproRegion/` |
| 命名空间 | `App\Apps\CmsproRegion\` | 各 PHP 文件命名空间 |
| 数据库隔离 | 2 张业务表，前缀 `app_cmspro_region_` | `Migrations/` |
| 服务提供者 | 注册路由/视图/配置 + 单例绑定 + 钩子注册 | `ServiceProvider.php` |
| 安装卸载 | 迁移 + 后台手动导入（避免超时） | `Install.php` |
| 配置 | `Config/region.php`（缓存时间/前缀/默认层级/地图配置默认值/区划显示级别默认值） | `Config/region.php` |
| 权限 | 3 项权限码 | `manifest.json` `permissions` |
| 菜单 | 后台 3 个子菜单 + 官网前台 1 个菜单 | `manifest.json` `menus` / `home_menus` |
| 数据源 | 中国国家地名信息库（民政部 API）；坐标在线更新数据源为阿里 DataV GeoJSON | `Services/RegionService.php` + `Data/` |

## 二、三终端全覆盖

| 终端 | 入口 | 说明 |
|------|------|------|
| 后台管理 | `/admin/cmspro/region` | 数据管理页 |
| 后台 API | `/api/admin/cmspro/region` | 区划数据查询/导入/清缓存/街道/地理位置/GeoJSON 坐标在线更新/快照导出/在线同步官方数据等 |
| 前台页面 | `/cmspro/region/demo` | 数据演示页（公开访问，无需登录，只读） |
| 前台 API | `/api/cmspro/region` | 公开只读接口：`provinces` / `children` / `path` / `list` |

> 本应用为基础设施类应用，主要面向其他应用提供数据服务；前台仅开放数据演示页与其配套的只读查询接口（`home_menus` 注册官网导航「区划演示」），导入、同步、快照、坐标更新等写操作一律仅限后台 `auth:admin`。

## 三、路由系统

| 路由组 | 前缀 | 中间件 | 实现位置 |
|--------|------|--------|----------|
| 后台页面 | `/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin.php` |
| 后台 API | `/api/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin_api.php` |
| 前台页面 | `/cmspro/region` | `web` | `Routes/demo.php` |
| 前台 API | `/api/cmspro/region` | `web` | `Routes/demo_api.php` |

> 前台路由文件刻意避开框架保留名 `home.php` / `front.php`（二者会被「绑定域名的应用」按 `Controllers\Home` / `Controllers\Front` 命名空间自动加载），故命名为 `demo.php` / `demo_api.php`。

## 四、数据层

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `app_cmspro_region_region` | 区划主表 | code（唯一，varchar(12)，村/社区 12 位）、name、level（1-省 2-市 3-区县 4-街道 5-村/社区）、parent_id、status、lon、lat |
| `app_cmspro_region_closure` | 闭包表 | ancestor、descendant、depth |

## 五、钩子系统

`manifest.json` 声明 `hooks: "Hooks"`，`Hooks.php` 注册 4 个 Filter 钩子：

| 钩子名 | 参数 | 返回值 | 说明 |
|--------|------|--------|------|
| `region.cascader_data` | 无 | array | 省市区三级级联数据 |
| `region.provinces` | 无 | Collection | 所有省份列表 |
| `region.children` | 父级 code | Collection | 指定父级的子级列表 |
| `region.path` | 区划 code | Collection | 完整路径（省→市→区） |

## 六、配置系统

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `cache_ttl` | 86400 | 缓存时间（秒） |
| `cache_prefix` | region | 缓存键前缀 |
| `default_max_level` | 3 | 默认最大层级 |
| `map_source` | none | 地图配置来源：none（Leaflet + 高德瓦片）/ own（本应用独立配置）/ maps（关联「地图」应用） |
| `map_provider` | baidu | 地图厂商：baidu / amap / tencent |
| `tencent_js_key`、`amap_js_key`、`amap_security_code`、`baidu_js_ak` | 空 | 三家厂商前端密钥，`Crypt` 加密落库，本应用不内置任何密钥 |
| `region_max_level` | 5 | 区划显示级别（1 省 ~ 5 村/社区），仅决定数据演示页「区划选择器」渲染到第几级下拉，不影响对外 API 返回、不删除库中数据 |
| `data_sync_version` | 空 | 已同步的官方数据版本号（系统自动维护，在线同步 / 导入快照包成功后写入） |
| `data_sync_level` | 空 | 已同步落库的最大级别（系统自动维护）；缺失时回退库中 `max(level)`，避免老站点重复全量导入 |

> 地图配置对应 `manifest.json` 的配置组 `map_settings`（框架生成 code `app_cmspro_region_map_settings`），区划显示级别对应配置组 `region_settings`（code `app_cmspro_region_region_settings`，标题「区划设置」），`data_sync_version` / `data_sync_level` 对应配置组 `sync_settings`（code `app_cmspro_region_sync_settings`，标题「数据同步设置」），均在「应用设置」页或同步链路中维护。配置组由框架在安装/升级时写入，**地图配置组需先升级到应用 v1.6.0、区划设置与数据同步组需先升级到 v1.7.0**；组缺失时 `region_settings` / `sync_settings` 会在写入时 `firstOrCreate` 自愈建组，`map_settings` 则拒绝写入并提示先升级。
>
> 区划显示级别与同步级别由 `Services/RegionLevelService.php`（单例）统一读写：`maxLevel()` / `setMaxLevel()`（非法值抛中文 `RuntimeException`）/ `syncedLevel()` / `saveSyncedLevel()`（同步成功后同时联动写入 `region_max_level`）/ `levelName()` / `levelLabel()`，读取带 3600 秒缓存。

## 七、权限系统

| 权限代码 | 权限名称 | 说明 |
|----------|----------|------|
| `cmspro.region.manage` | 区划管理 | 数据管理基础权限 |
| `cmspro.region.import` | 数据导入 | 数据导入权限 |
| `cmspro.region.export` | 数据导出 | 导出全量数据快照包权限 |
| `cmspro.region.setting.manage` | 应用设置管理 | 应用设置（区划级别 + 地图配置）查看与保存权限 |

## 八、安装卸载升级

| 操作 | 行为 | 实现位置 |
|------|------|----------|
| 安装 | 执行迁移（**不自动导入数据**，避免超时） | `Install::install()` |
| 卸载 | 回滚迁移 | `Install::uninstall()` |
| 升级 | 执行迁移 | `Install::upgrade()` |

> 安装后数据表为空，需进入后台「行政区划 → 数据管理」手动导入数据。另有 `Migrations/2026_06_25_add_geo_columns.php` 添加经纬度列。

## 九、视图规范

| 视图目录 | 说明 |
|----------|------|
| `Views/Admin/` | 后台数据管理页（`index.blade.php`）、数据演示页（`demo.blade.php`）、应用设置页（`setting.blade.php`） |

遵循 CmsPro 应用视图规范（Layui 后台模板）。

## 十、菜单系统

| 菜单 | 路径 | 类型 |
|------|------|------|
| 数据管理 | `/admin/cmspro/region` | 后台 |
| 数据演示 | `/admin/cmspro/region/demo` | 后台 |
| 应用设置 | `/admin/cmspro/region/setting` | 后台 |
| 区划演示 | `/cmspro/region/demo` | 前台（官网导航，`home_menus`，`code=home_cmspro_region_demo`） |

## 十一、版本历史

| 版本 | 日期 | 更新人 | 变更内容 |
|------|------|--------|----------|
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本（对应应用 v1.0.1） |
| 1.1.0 | 2026-09-13 | 开发团队 | 新增数据演示页面（对应应用 v1.0.2） |
| 1.2.0 | 2026-09-13 | 开发团队 | 新增在线更新坐标（GeoJSON，阿里 DataV 源）+ 编码差异名称兜底；数据演示新增 Leaflet 地图定位 |
| 1.3.0 | 2026-09-13 | 开发团队 | 修复港澳台数据问题：①normalizeCode 非数字编码防护（杜绝民政部 API 返回"资料暂缺"被截断入库成 code='资料' 脏数据，6 处调用方同步加空串跳过）；②新增「补全港澳台」一键修复（修正台湾省级脏编码为 710000，阿里 DataV 源补全香港 18 区/澳门 8 区级节点及坐标，重建闭包表并清缓存） |
| 1.4.0 | 2026-09-13 | 开发团队 | 官方数据包导出：从开发库全量导出为应用官方数据——list.json 重建为树形三级格式并首次包含港澳台（34 省/333 市/2872 区县），地理位置编码.xlsx 重建为全量坐标快照（3193 行，含省市/港澳台省级坐标）；Data/code/ 街道缓存 2837 文件与库完全一致校验通过。新装应用可直接按省导入官方数据 |
| 1.5.0 | 2026-09-13 | 开发团队 | 新增「一键数据更新」：后台数据管理页单按钮串联四阶段在线更新流程（① 三级区划：民政部 API 逐省分步 → ② 港澳台补全：阿里 DataV，失败不中止 → ③ 街道数据：逐区县民政部 API（本地缓存优先），失败区县自动追加一轮重试 → ④ 最新坐标：阿里 DataV GeoJSON 逐省），统一进度条按阶段分段映射（0~5%/5~30%/30~85%/85~99%），执行期间按钮互斥；官方数据包（Data/ 目录）作为新装基线集合数据 |
| 1.6.0 | 2026-11-06 | 开发团队 | 新增「导出快照包」：`RegionSnapshotService` + 「导出快照包」按钮 + `GET /api/admin/cmspro/region/export-snapshot` 接口，将库中全量区划导出为标准化 ZIP 快照包（regions.json + meta.json，`cmspro-region-data-{版本号}.zip`），供上传版本服务器（Versionsvr「区划数据」服务）发布分发，使区划数据更新与区划应用发版解耦；导出契约与版本服务器校验规则完全对齐（level+code 排序、parent_id→parent_code 转换、顶级 parent_code='0'、status 0/1 全量导出、无坐标 lon/lat=null、meta.json 含 version/count/stats/generated_at/format）；新增权限 `cmspro.region.export`（对应应用 v1.2.0） |
| 1.7.0 | 2026-11-06 | 开发团队 | 新增「在线同步官方数据」（消费端）：`RegionDataSyncService` + 「在线同步官方数据」按钮 + `GET sync-check-update` / `POST sync-region-data` 接口；同步链路：检查更新（版本服务器 `region-data/latest`）→ 下载 SHA-256 校验（`region-data/download/{version}`）→ 解包（防 Zip Slip）→ 契约校验 → 单事务全量替换落库（全删 → 按 level 分层 chunk(500) 批量插入、parent_code→parent_id、闭包表重建）→ 清缓存 → 记版本（配置组「数据同步设置」+ 配置项 `app_cmspro_region_data_sync_version`）；五重安全网（校验和/前置契约/事务回滚/版本回退/重复跳过）；站点初始化从约 1 小时降至 1 分钟内（49334 条实测校验+落库 5.84s）（对应应用 v1.3.0） |
| 1.8.0 | 2026-11-06 | 开发团队 | 新增「导入快照包」（站点间分享）：`RegionDataSyncService::importFromSnapshot()` + 「导入快照包」按钮 + `POST /api/admin/cmspro/region/import-snapshot` 接口（zip ≤50MB）；接收其他站点「导出快照包」生成的 ZIP 直接上传导入，复用同一套契约校验（防 Zip Slip/必填/code 唯一/引用闭环/省级必含）与单事务全量替换落库链路（parent_code→parent_id、闭包表重建）及进度轮询机制；版本号取包内 meta.json（缺失回退 `snapshot-{YmdHis}`）；因上传包无可信校验和来源不做 SHA-256 校验，仅限管理员导入可信站点的包（对应应用 v1.4.0） |
| 1.9.0 | 2026-11-07 | 开发团队 | 新增**行政村/社区五级数据**（对应应用 v1.5.0）：① code 字段扩容迁移 varchar(9)→varchar(12)，支持村级 12 位编码；② 导出/导入/在线同步契约支持五级（level 1-5、code ≤12 位、上限放宽至 60 万条、stats 增 village，与版本服务器同步）；③ 同步链路流式化——解包转存 regions.json、契约校验流式写 JSONL 中间产物、按层级分遍扫描批量插入（2000 条/批）、层间 code 映射 `chunkById` 流式收集，58.4 万条五级全量同步（省 34/市 333/区县 2,872/街道 38,598/村 542,428）内存峰值 86MB（512M 限制，MySQL 实测）；④ 闭包表重建 SQL 化（`INSERT...SELECT` 逐层展开，58 万节点约 31 分钟 → 约 1 分钟）；⑤ `clearCache()` 版本号命名空间失效（O(1)）：children/path 动态缓存键带版本号，旧键由 TTL 自然过期；⑥ 数据管理页与数据演示页新增村级统计与级联选择 |
| 1.10.0 | 2026-11-07 | 开发团队 | 新增**应用设置（地图配置）**（对应应用 v1.6.0）：① 新增后台菜单「应用设置」（`/admin/cmspro/region/setting`）、权限 `cmspro.region.setting.manage`、`SettingController`（index/update）与 `setting.blade.php`；② 新增 `MapSettingService`（单例）解析生效地图配置，三种来源——`none`（默认，Leaflet + 高德瓦片）/ `own`（本应用独立配置厂商与密钥）/ `maps`（跟随 CmsproMaps 的 `provider` 与前端密钥，未安装或未配置时自动回退）；③ manifest 新增配置组 `map_settings`（6 项，密钥类 `type=password`），四项前端密钥 `Crypt` 加密落库、缓存 TTL 3600 秒，配置组未注册时拒绝写入并提示先升级应用；④ 数据演示页地图层重构为厂商适配器（百度 BMapGL / 高德 AMap 2.0 / 腾讯 TMap GL），统一接口 `init/showPoint/clearPoint/drawRings/clearBoundary/fitAll/resize`，坐标系按厂商转换（库内 GCJ-02，百度转 BD-09），SDK 加载失败自动回退 Leaflet 并补绘边界；⑤「更新坐标」开发模式 AK 优先取本应用生效配置、回退「地图」应用。新增测试 `Tests/Feature/MapSettingTest.php`（22 项），应用测试 81 passed / 439 assertions |
| 1.10.1 | 2026-11-07 | 开发团队 | 新增**同步版本回退保护** + 单次记录数上限 60 万 → 70 万（应用版本不变）：① `checkUpdate` 在官方最新版本低于本地版本时返回 `need_update=false`（message 说明本地版本已高于官方），`sync` 不带 `version` 参数时拒绝自动同步——防止本地先行修正数据被官方旧包全量回退，显式指定版本号的历史回退不受限；`RegionDataSyncTest` 增补 2 项防回归用例（93 passed / 484 assertions）；② `MAX_REGIONS` 600,000 → 700,000（`RegionDataSyncService` 与版本服务器 `Versionsvr\RegionDataService` 同步放宽）——数据版本 `20261107002` 总量 638,772 条（省 34/市 333/区县 2,872/街道 38,717/村 596,814）超原 60 万上限，不放宽则各站点同步该校验直接失败 |
| 1.11.0 | 2026-11-08 | 开发团队 | 数据演示页开放**前台公开访问**并注册官网前台菜单（对应应用 v1.6.2）：① 新增前台路由组 `Routes/demo.php`（`GET /cmspro/region/demo`）与 `Routes/demo_api.php`（`GET /api/cmspro/region/{provinces,children,path,list}`），中间件仅 `web`、无需登录；文件名避开框架保留名 `home.php`/`front.php`，防止「绑定域名应用」按 `Controllers\Home`/`Controllers\Front` 自动加载造成语义冲突；② `RegionController::demo()` 用 `->defaults('demoMode','public')` 区分前后台，注入 `$apiBase`/`$publicMode`，同一视图双端复用（前台隐藏导入引导与后台链接、空态文案改为联系管理员，JS 请求统一走 `API_BASE`）；③ 安全边界：前台仅 4 个查询类 GET，写操作（导入/同步/快照/坐标）未在前台前缀注册（返回 404），`list` 的 `page`/`limit` 限定（`limit` ≤200），后台 API 仍 `auth:admin`；④ manifest 新增 `home_menus`「区划演示」（`code=home_cmspro_region_demo`、`path=/cmspro/region/demo`、`order=50`），由框架 `AppInstallerService::syncMenusByTerminal()` 在安装/升级时写入 `admin_menus`（`terminal_type=home`）供官网导航渲染，升级不覆盖已修改的 `sort`/`status`/`visible`。新增 `Tests/Feature/FrontDemoTest.php`（6 项），应用测试 99 passed / 566 assertions |
| 1.12.0 | 2026-11-08 | 开发团队 | 新增**在线同步级别可选**与**区划级别设置**（对应应用 v1.7.0）：① 在线同步弹窗增加「同步数据级别」单选（3 区县约 3,239 条 / 4 街道约 41,956 条 / 5 村社区约 638,772 条，每项标注累计条数，默认选中已同步级别），`RegionDataSyncService::sync($version, $maxLevel)` 按级别裁剪落库——裁剪前置到契约校验阶段（深于所选级别的记录不进编码集合、不写 JSONL、不落库），`importRegionRows` 按 `range(1, $maxLevel)` 分遍插入，接口 `max_level` 仅接受 3/4/5（非法值返回 `code=1` 且零远端请求），浅级别可把落库量降低两个数量级以减轻数据库压力；② 重复保护由「版本相同即跳过」改为「版本相同**且已同步级别不浅于请求级别**才跳过」，`checkUpdate()` 增返 `local_level`，支持在同一版本上补齐更深层级；③ 新增配置项 `data_sync_level`（已同步级别，缺失时回退库中 `max(level)`）与 `region_max_level`（区划显示级别 1~5 默认 5）、新建配置组 `region_settings`（缺失时 `firstOrCreate` 自愈），同步 / 导入快照包成功后自动联动写入显示级别；④ 新增 `RegionLevelService`（单例：`maxLevel`/`setMaxLevel`/`syncedLevel`/`saveSyncedLevel`/`isValidLevel`/`levelName`/`levelLabel`，读取缓存 3600 秒，非法值抛中文 `RuntimeException`），`ServiceProvider` 注册单例，`Config/region.php` 增 `region_max_level` 默认值；⑤ 「应用设置」页新增「区划级别设置」卡片（与地图配置共用同一表单与保存接口，`SettingController` 私有 `saveRegionLevel()`，请求不含该字段时不影响级别）；⑥ 数据演示页各级下拉改为「库中有该级数据 && 设置级别 ≥ 该级」才渲染（控制器新增 `$canSelect`/`$showDistrict`/`$showStreet`/`$showVillage`/`$maxLevel`/`$levelLabel`），级别低于 3 时降级为省 / 市两级，状态卡片仍按库中真实数据量显示并提示当前设置级别与「前往调整」入口——**对外 API 与 `RegionService` 契约零改动**；⑦ 数据管理页空数据提示与数据演示页「数据导入引导」三张卡片 / 选择器空态文案统一改为指向「在线同步官方数据 / 导入快照包」双入口（街道卡片说明同步级别需选「街道」级或「村/社区」级，地理位置卡片说明坐标随同步包/快照包一并落库、「一键导入」仅在开发模式的旧版面板内）。新增 `Tests/Feature/RegionLevelTest.php`（11 项）与 `RegionDataSyncTest` 4 项，应用测试 114 passed / 669 assertions |
| 1.12.1 | 2026-11-09 | 开发团队 | **村/社区数据回填**（纯数据变更，应用版本不变、无新增特性）：迁移 `V2026110901` 写入 14,291 个本地缺失上级乡镇的村/社区（数据源 `Data/backfill_unmatched_villages_20261109.csv`，按 code 幂等，`down()` 可精确回滚），level=5 由 606,144 增至 **620,435**、总记录 662,393、闭包表 3,215,228（+60,511），L3/L4 零变化，只读验证 14 项全 PASS；对账闭合 14,425 = 14,291 写入 + 9 同名跳过 + 125 真实缺口（待人工处置）。风险提示见扩展指南「注意事项」第 16 条：在线同步 / 导入快照包为全量替换，会抹掉本批数据，恢复方式为重新执行应用升级触发迁移 |
| 1.12.2 | 2026-11-10 | 开发团队 | **村/社区坐标手工补全**（纯数据变更，应用版本不变、无新增特性、未新增迁移）：为 65 个村/社区补写 GCJ-02 坐标，覆盖湖北仙桃市 7 / 天门市 3、新疆石河子市 8 / 五家渠市 8 / 铁门关市 36 / 图木舒克市 2 / 阿拉尔市 1——这批村在「在线更新坐标(GeoJSON)」下取不到值（阿里 DataV 仅收录到区县级，村级 12 位编码无数据），按人工整理结果直接落库；写入带 `whereNull('lon')` 护栏，只补空值、覆盖 0 条既有坐标，坐标补齐后数据演示页选中这些村可正常地图定位。同时按人工裁决合并石河子市 3 条同名重复记录（「十九连生活区」保留 `652324521517`、删 `652324522518` / `652324524521`；「砖厂生活区」保留 `650203530514`、删 `652324522523`），闭包表同步清理 9 行，孤儿节点 0。变更后 level=5 **620,432**、总记录 **662,390**、闭包表 **3,215,219**；铁门关市遗留 2 条无坐标（`652829501504` 六连队、`652829501506` 二连队）。风险提示见扩展指南「注意事项」第 16 条：在线同步 / 导入快照包为全量替换，会抹掉这批坐标并让被删的同名记录随官方包重新出现 |
| 1.12.3 | 2026-11-10 | 开发团队 | **同父级同名重复记录合并**（纯数据变更，应用版本不变、无新增特性、未新增迁移）：按 `GROUP BY (parent_id, name)` 分三级排查——区/县 0 组、街道/乡镇 0 组、村/社区 **322 组 / 1,098 条**；经用户裁决全量合并、每组保留 `code` 升序第 1 条，删除 776 条主表记录 + 2,709 行闭包关系。坐标距离分类核验 A 47 / B 273 / D 2、**C（异地同名）0 组**（320 组组内最大距离 <0.2km），无误删风险；六项预检 + 单事务执行 + 六项回读验证全部通过。变更后 level=5 **619,656**、总记录 **661,614**、闭包表 **3,212,510**，L1~L4 零变化，剩余同名重复 0 组、孤儿节点 0；顺带清零 1.12.2 遗留的铁门关市 2 条无坐标村。风险提示见扩展指南「注意事项」第 16 条：在线同步 / 导入快照包为全量替换，这 776 条会随官方包重新出现，需重跑合并 |
| 1.12.4 | 2026-11-10 | 开发团队 | 新增**数据管理页双击行查看区划完整详情**（应用版本不变，仅 `Views/Admin/index.blade.php` 单文件改动，后端接口 / 路由 / 数据库零改动）：双击区划列表任意一行弹出 `layer.open` 详情层，展示层级路径（当前节点高亮）与 `region` 表全部字段（ID、区划代码、名称、层级、上级区划名称+代码、上级 ID、状态、经纬度 GCJ-02 保留 6 位小数、创建时间、更新时间）外加直接子级数量。**复用既有接口不新增**：`list` 已返回模型全字段，详情仅并发调用 `path`（闭包祖先链）与 `children`（子级数量），`level>=5` 最末级不发 `children`；layui `table` 无行双击事件，改在 `done` 回调按 `data-index` 从 `table.cache` 取行数据并绑定带命名空间的 `dblclick`，重载先解绑。两个请求以计数 + `.always()` 收敛，任一失败仍弹层并显示占位提示；数据库取值统一 HTML 转义，日期直接展示 `$casts` 输出不做二次格式化。验证：Blade 编译 `php -l` + 内联脚本 `node --check` 通过，抽取渲染函数注入真实库数据（村级最末节点 5 级路径 / 北京市顶级节点 4 级路径、子级 16）在 node 中输出 HTML 逐项核对，并在项目实际使用的 `layui.js` 中核实 `data-index` 与 `table.cache` 契约 |
| 1.12.5 | 2026-11-10 | 开发团队 | 「更新坐标」不再依赖开发模式（应用版本不变）：① 数据管理页「更新坐标」按钮移出 `@if($devMode)` 常驻显示，该开关现仅控制「旧版数据更新」面板；百度 JSAPI 由 `@if($baiduAk)` 单独控制；② `RegionController` 四个坐标接口（`update-coordinate` / `coordinate` / `coordinate-candidates` / `update-coordinate-batch`）删除 `APP_DEV_MODE` 拦截；③ `index()` 始终解析生效地图配置取 AK，AK 缺失时照常输出 `coordMapTip`（前端点击按钮弹「地图未配置」确认框引导到应用设置），`devBaiduAk()` 更名 `baiduJsAk()`；④ 弹窗标题与按钮提示去掉「开发模式」字样；⑤ 测试同步：`CoordinateMapCheckTest` 首用例改为「非开发模式也渲染更新坐标入口（且不渲染旧版面板）」，`CoordinateQueryTest`「非开发模式拒绝查询」改为「非开发模式也可查询经纬度」，应用测试 114 passed / 671 assertions |