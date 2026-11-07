# CmsPro-中国行政区划-特性清单

> 版本：1.10.0 | 更新日期：2026-11-07
> 应用版本：1.6.0 | 应用 ID：cmspro.region

---

## 一、应用体系架构

| 特性 | 说明 | 实现位置 |
|------|------|----------|
| 目录结构 | 应用级代码位于 `app/Apps/CmsproRegion/` | `app/Apps/CmsproRegion/` |
| 命名空间 | `App\Apps\CmsproRegion\` | 各 PHP 文件命名空间 |
| 数据库隔离 | 2 张业务表，前缀 `app_cmspro_region_` | `Migrations/` |
| 服务提供者 | 注册路由/视图/配置 + 单例绑定 + 钩子注册 | `ServiceProvider.php` |
| 安装卸载 | 迁移 + 后台手动导入（避免超时） | `Install.php` |
| 配置 | `Config/region.php`（缓存时间/前缀/默认层级） | `Config/region.php` |
| 权限 | 3 项权限码 | `manifest.json` `permissions` |
| 菜单 | 后台 2 个子菜单 | `manifest.json` `menus` |
| 数据源 | 中国国家地名信息库（民政部 API）；坐标在线更新数据源为阿里 DataV GeoJSON | `Services/RegionService.php` + `Data/` |

## 二、三终端全覆盖

| 终端 | 入口 | 说明 |
|------|------|------|
| 后台管理 | `/admin/cmspro/region` | 数据管理页 |
| 后台 API | `/api/admin/cmspro/region` | 区划数据查询/导入/清缓存/街道/地理位置/GeoJSON 坐标在线更新/快照导出/在线同步官方数据等 |

> 本应用为基础设施类应用，主要面向其他应用提供数据服务，无前台用户端/用户端菜单。

## 三、路由系统

| 路由组 | 前缀 | 中间件 | 实现位置 |
|--------|------|--------|----------|
| 后台页面 | `/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin.php` |
| 后台 API | `/api/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin_api.php` |

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

> 地图配置对应 `manifest.json` 的配置组 `map_settings`（框架生成 code `app_cmspro_region_map_settings`），在「应用设置」页维护；配置组由框架在安装/升级时写入，**旧版本站点需先升级到应用 v1.6.0** 才能保存。

## 七、权限系统

| 权限代码 | 权限名称 | 说明 |
|----------|----------|------|
| `cmspro.region.manage` | 区划管理 | 数据管理基础权限 |
| `cmspro.region.import` | 数据导入 | 数据导入权限 |
| `cmspro.region.export` | 数据导出 | 导出全量数据快照包权限 |
| `cmspro.region.setting.manage` | 应用设置管理 | 地图配置查看与保存权限 |

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