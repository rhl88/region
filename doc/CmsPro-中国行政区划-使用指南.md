# CmsPro-中国行政区划-使用指南

> 版本：1.0.0 | 更新日期：2026-08-13
> 应用版本：1.0.1 | 应用 ID：cmspro.region

---

## 一、环境要求

| 项目 | 要求 |
|------|------|
| 系统 | CmsPro v5.0.0+ |
| PHP | >= 8.3 |
| 数据库 | MySQL |
| 网络 | 导入数据需访问民政部 API（`https://dmfw.mca.gov.cn`） |
| 依赖应用 | 无 |

---

## 二、安装

1. 通过 CmsPro 后台「应用市场」安装中国行政区划应用，或手动将应用包上传安装。
2. 安装过程中自动创建数据库表（`app_cmspro_region_region` 和 `app_cmspro_region_closure`）。
3. **注意：安装后数据表为空，需进入后台「行政区划 → 数据管理」页面手动导入数据。**

> 卸载应用会回滚业务数据表。

---

## 三、功能验证

### 3.1 后台验证

后台入口前缀 `/admin/cmspro/region`（中间件 `web + auth:admin`）：

| 菜单 | 路径 | 验证点 |
|------|------|--------|
| 数据管理 | `/admin/cmspro/region` | 数据导入/更新、街道分步导入、缓存清除、级联预览、数据对比更新、地理位置导入 |

### 3.2 导入三级数据

安装完成后，在后台「行政区划 → 数据管理」页面中，点击「导入三级数据」按钮导入省/市/区县数据。由于三级数据有 3400+ 条，系统采用**按省份分步导入**策略：

1. 前端获取省份列表（34 个）。
2. 逐个省份通过 AJAX 请求导入，每次请求处理一个省份。
3. 进度条实时显示导入进度，日志记录每个省份的导入结果。
4. 每个省份独立请求，即使服务器配置较低也不会超时。

### 3.3 启用街道级数据（可选）

如需使用街道/乡镇四级数据，在 CmsPro 后台「系统设置 → 区划设置」中开启「启用街道级数据」选项。开启后在后台页面点击「导入街道数据」按钮，系统按省份/区县分步导入（约 4 万+ 条），支持本地缓存文件 `Data/code/{区县代码}.json`。

### 3.4 导入地理位置数据（可选）

如需使用城市和区县的经纬度信息，在后台页面点击「导入地理位置」按钮。数据来源为本地 Excel 文件 `Data/地理位置编码.xlsx`，按省份分步导入。

### 3.5 API 验证

统一响应格式：`{"code": 0, "message": "success", "data": ...}`，`code = 0` 成功。

```bash
# 获取所有省份列表
curl "http://www.cmspro.com/api/admin/cmspro/region/provinces"

# 获取子级区划列表
curl "http://www.cmspro.com/api/admin/cmspro/region/children?code=110000"

# 获取完整树形数据
curl "http://www.cmspro.com/api/admin/cmspro/region/tree?max_level=3"

# 获取完整路径
curl "http://www.cmspro.com/api/admin/cmspro/region/path?code=110101"

# 获取数据统计
curl "http://www.cmspro.com/api/admin/cmspro/region/stats"
```

---

## 四、卸载验证

1. 在 CmsPro 后台卸载中国行政区划应用。
2. 确认 `app_cmspro_region_region`、`app_cmspro_region_closure` 两张表被回滚删除。

---

## 五、目录结构

```
app/Apps/CmsproRegion/
├── ServiceProvider.php       # 服务提供者：路由/视图/配置 + 单例绑定 + 钩子注册
├── Install.php               # 安装/卸载/升级：迁移（数据手动导入）
├── Config/region.php         # 应用配置（缓存时间/前缀/默认层级）
├── Routes/                   # admin.php / admin_api.php
├── Controllers/Admin/        # RegionController（数据管理）
├── Services/RegionService.php # 核心服务（单例，供其他应用引用）
├── Models/                   # Region / RegionClosure
├── Migrations/               # 区划主表 + 闭包表
├── database/migrations/      # 经纬度列迁移
├── Data/                     # 本地数据缓存（list.json / code/*.json / 地理位置编码.xlsx）
├── Hooks.php                 # 钩子注册
└── Views/Admin/              # 数据管理页
```

---

## 六、常见问题

### Q: 安装后没有数据？

安装应用仅创建数据库表，需在后台「行政区划 → 数据管理」页面手动点击「导入三级数据」导入。

### Q: 导入数据提示超时？

三级数据采用按省份分步导入策略，每个省份独立请求，不会超时。如仍异常，请检查服务器能否访问民政部 API。

### Q: 如何启用街道/乡镇四级数据？

在 CmsPro 后台「系统设置 → 区划设置」中开启「启用街道级数据」选项，然后在数据管理页面点击「导入街道数据」。

### Q: 数据更新后前台不生效？

查询结果缓存 24 小时，数据更新时自动清除；如仍异常，可手动点击「清除缓存」。

### Q: 直辖市如何查询子级？

北京、天津、上海、重庆的区县直接挂在省级下（无中间市级），`getChildren('110000')` 直接返回区县列表。

---

## 七、版本历史

| 版本 | 日期 | 更新人 | 变更内容 |
|------|------|--------|----------|
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本（对应应用 v1.0.1） |