# 中国行政区划 应用文档

> 应用ID：`cmspro.region` | 文档版本：1.3.3 | 应用版本：1.7.2 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：https://www.cmspro.cn/apps/cmspro.region
- 本应用提供中国五级行政区划数据的管理、同步、查询与可视化演示能力，基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

面向需要行政区划数据支撑的站点，统一解决「数据从哪来、如何落库、如何查询、如何展示」四个问题：数据源既支持从版本服务器在线同步官方数据（分钟级全量替换），也支持离线本地数据包导入与站点间快照包分享；对外提供级联查询、路径查询等 API 供其他应用复用；对内提供后台数据管理页与可视化演示页，并额外开放一个无需登录的前台演示页。

- **数据管理**：省/市/区县/街道/村社区五级统计卡片、按层级与名称或代码筛选搜索、双击行查看区划完整详情（层级路径、代码、上级、子级数量、经纬度、时间戳）、缓存清理
- **数据同步**：在线同步官方数据（可按 3/4/5 级别选择同步深度、单事务全量替换 + 闭包表重建、失败整体回滚、同步版本回退保护、单次上限 70 万条、实时进度反馈）/ 导入其他站点分享的快照包 ZIP / 导出全量快照包供版本服务器发布
- **旧版导入链路**：一键导入（应用内置本地数据包，离线可用）、一键数据更新（民政部 + 阿里 DataV 在线源增量更新），收纳在工具栏下拉中，仅 `APP_DEV_MODE=true` 开发模式可见
- **地理坐标**：本地 Excel 地理位置导入、阿里 DataV GeoJSON 在线更新坐标、百度地址解析更新坐标（单条与批量补齐）、地图手动选点纠正偏差坐标、GCJ-02 坐标系并按厂商自动转换
- **数据演示**：最多五级级联选择器、地址位置信息、地图定位与行政区边界绘制；底图支持 Leaflet + 高德瓦片与百度 `BMapGL` / 高德 `AMap` 2.0 / 腾讯 `TMap` GL 三家官方 JS API，SDK 加载失败自动降级
- **前台公开访问**：`/cmspro/region/demo` 只读演示页（中间件仅 `web`），走公开只读 API 且分页参数受保护，并在 `home_menus` 注册官网导航菜单
- **应用设置**：地图配置（来源 `none`/`own`/`maps`，三家厂商密钥独立配置或跟随「地图」应用）、区划显示级别 `region_max_level` 控制演示页选择器渲染深度
- **API 服务**：后台管理接口（导入、在线同步、快照导入导出、坐标更新、统计、闭包表重建等）与前台公开只读接口（`provinces` / `children` / `path` / `list`，分页参数受保护），统一 `{"code":0,"message":"success","data":...}` 响应格式，供其他应用调用

## 2. 应用截图

> 截图来源：[官方应用页 · cmspro.region](https://www.cmspro.cn/apps/cmspro.region)（官网标注 1.7.0）。每次发布与更新必须核对本节截图与官网「应用截图」是否一致，不一致时以官网为准同步更新。

![应用截图 1](https://www.cmspro.cn/uploads/2026/11/10/LdK75oxlbj0rYihWKOKWvH4OcMpVq7zWZu3EGncN.png)

![应用截图 2](https://www.cmspro.cn/uploads/2026/11/10/I7urx9HeJT7N3AJdkmxs7mvEgcH49d1u5rfVvIXQ.png)

![应用截图 3](https://www.cmspro.cn/uploads/2026/11/10/XCUmVlaEtxDFwrF1omeYP57FGf3XpbvrGi7CkuJM.png)

![应用截图 4](https://www.cmspro.cn/uploads/2026/11/10/TPZ5FXleSJ8imXweDPEu3haQGmpAxv155mu3ugdq.png)

![应用截图 5](https://www.cmspro.cn/uploads/2026/11/10/AD5mzBFVjPbajHlrS8ktFGDtJSHtBcTXsvF6fVDF.png)

## 3. 目录结构

```
CmsproRegion/
├── Config/region.php                 # 应用默认配置（同步级别、缓存策略等）
├── Controllers/Admin/                # 后台控制器（RegionController 数据管理/演示、SettingController 应用设置）
├── Data/                             # 本地数据源（list.json、code/*.json 街道包、地理位置编码.xlsx）
├── Migrations/                       # 应用表迁移（区划表、闭包表、geo 列、code 长度、level+code 索引、村社区回填）
├── Models/                           # Region 区划模型、RegionClosure 闭包表模型
├── Routes/                           # admin.php 后台页面、admin_api.php 后台接口、demo.php 前台演示页、demo_api.php 前台公开只读接口
├── Services/                         # RegionService 区划业务、RegionDataSyncService 在线同步与快照导入、RegionSnapshotService 快照导出、RegionLevelService 区划级别、MapSettingService 地图配置
├── Tests/Feature/                    # 应用功能测试
├── Views/Admin/                      # 后台视图（index 数据管理、demo 数据演示、setting 应用设置）
├── doc/                              # 应用文档
├── .exportignore                     # 应用打包导出排除规则
├── Hooks.php                         # 应用钩子
├── icon.svg                          # 应用图标
├── Install.php                       # 安装/卸载/升级逻辑
├── manifest.json                     # 应用元数据唯一声明源
├── README.md                         # 本文件
└── ServiceProvider.php               # 路由、视图命名空间与配置注册
```

## 4. 应用文档

| 文档 | 说明 |
|------|------|
| [CmsPro-中国行政区划-使用指南.md](doc/CmsPro-中国行政区划-使用指南.md) | 环境要求、安装步骤、各功能的后台验证方法与 API 说明，面向使用者与运维 |
| [CmsPro-中国行政区划-特性清单.md](doc/CmsPro-中国行政区划-特性清单.md) | 功能特性与对应实现位置的逐项清单，面向快速定位代码 |
| [CmsPro-中国行政区划应用扩展指南.md](doc/CmsPro-中国行政区划应用扩展指南.md) | Service 调用方式、钩子点、数据表结构等扩展接口说明，面向二次开发者 |
