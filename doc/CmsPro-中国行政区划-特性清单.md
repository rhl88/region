# CmsPro-中国行政区划-特性清单

> 版本：1.0.0 | 更新日期：2026-08-13
> 应用版本：1.0.1 | 应用 ID：cmspro.region

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
| 权限 | 2 项权限码 | `manifest.json` `permissions` |
| 菜单 | 后台 2 个子菜单 | `manifest.json` `menus` |
| 数据源 | 中国国家地名信息库（民政部 API）；坐标在线更新数据源为阿里 DataV GeoJSON | `Services/RegionService.php` + `Data/` |

## 二、三终端全覆盖

| 终端 | 入口 | 说明 |
|------|------|------|
| 后台管理 | `/admin/cmspro/region` | 数据管理页 |
| 后台 API | `/api/admin/cmspro/region` | 区划数据查询/导入/清缓存/街道/地理位置/GeoJSON 坐标在线更新等 |

> 本应用为基础设施类应用，主要面向其他应用提供数据服务，无前台用户端/用户端菜单。

## 三、路由系统

| 路由组 | 前缀 | 中间件 | 实现位置 |
|--------|------|--------|----------|
| 后台页面 | `/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin.php` |
| 后台 API | `/api/admin/cmspro/region` | `web`, `auth:admin` | `Routes/admin_api.php` |

## 四、数据层

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `app_cmspro_region_region` | 区划主表 | code（唯一）、name、level（1-省 2-市 3-区县 4-街道）、parent_id、status、lon、lat |
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

## 七、权限系统

| 权限代码 | 权限名称 | 说明 |
|----------|----------|------|
| `cmspro.region.manage` | 区划管理 | 数据管理基础权限 |
| `cmspro.region.import` | 数据导入 | 数据导入权限 |

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
| `Views/Admin/` | 后台数据管理页（`index.blade.php`）、数据演示页（`demo.blade.php`） |

遵循 CmsPro 应用视图规范（Layui 后台模板）。

## 十、菜单系统

| 菜单 | 路径 | 类型 |
|------|------|------|
| 数据管理 | `/admin/cmspro/region` | 后台 |
| 数据演示 | `/admin/cmspro/region/demo` | 后台 |

## 十一、版本历史

| 版本 | 日期 | 更新人 | 变更内容 |
|------|------|--------|----------|
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本（对应应用 v1.0.1） |
| 1.1.0 | 2026-09-13 | 开发团队 | 新增数据演示页面（对应应用 v1.0.2） |
| 1.2.0 | 2026-09-13 | 开发团队 | 新增在线更新坐标（GeoJSON，阿里 DataV 源）+ 编码差异名称兜底；数据演示新增 Leaflet 地图定位 |
| 1.3.0 | 2026-09-13 | 开发团队 | 修复港澳台数据问题：①normalizeCode 非数字编码防护（杜绝民政部 API 返回"资料暂缺"被截断入库成 code='资料' 脏数据，6 处调用方同步加空串跳过）；②新增「补全港澳台」一键修复（修正台湾省级脏编码为 710000，阿里 DataV 源补全香港 18 区/澳门 8 区级节点及坐标，重建闭包表并清缓存） |