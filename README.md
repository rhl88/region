# 中国行政区划 应用文档

> 应用ID：`cmspro.region` | 文档版本：1.1.0 | 应用版本：1.2.0 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：https://www.cmspro.cn/apps/cmspro.region

## 1. 应用概述

中国行政区划数据服务应用，提供省、市、区县三级及街道四级行政区划数据的管理、导入与查询能力：

- **数据管理**：三级数据（省/市/区县）、街道四级数据、地理位置数据（经纬度坐标）的导入与统计
- **数据演示**：三级联动选择、街道四级选择、地理信息展示；选中含坐标区划时在 Leaflet + 高德瓦片地图上自动定位标记
- **API 服务**：供其他应用调用的区划查询接口（级联选择器、路径查询、IP 归属地等）
- **快照导出**：将库中全量区划导出为标准化 ZIP 快照包（regions.json + meta.json），供上传版本服务器（Versionsvr「区划数据」服务）发布分发，使区划数据更新与本应用发版解耦

## 2. 目录结构

```
CmsproRegion/
├── Config/                           # 应用配置
├── Controllers/Admin/                # 后台控制器（数据管理/数据演示）
├── Data/                             # 行政区划数据源（list.json 及 code/*.json）
├── Migrations/                       # 应用表迁移（含 lon/lat 地理坐标列）
├── Models/                           # 区划模型与闭包表模型
├── Services/RegionService.php        # 区划业务逻辑（导入/查询/统计）
├── Services/RegionSnapshotService.php # 全量快照导出（regions.json + meta.json 打 ZIP）
├── Routes/admin.php                  # 后台路由
├── Tests/                            # 应用功能测试
├── Views/Admin/                      # 后台视图（数据管理/数据演示页）
├── Install.php                       # 安装/卸载/升级逻辑
├── manifest.json                     # 应用元数据唯一声明源
└── doc/                              # 应用文档
```

## 3. 应用文档

| 文档 | 说明 |
|------|------|
| [CmsPro-中国行政区划-特性清单.md](doc/CmsPro-中国行政区划-特性清单.md) | 功能特性与实现位置 |
| [CmsPro-中国行政区划-使用指南.md](doc/CmsPro-中国行政区划-使用指南.md) | 安装、数据导入、功能验证指南 |
| [CmsPro-中国行政区划应用扩展指南.md](doc/CmsPro-中国行政区划应用扩展指南.md) | Service 调用、钩子点等扩展接口说明 |
