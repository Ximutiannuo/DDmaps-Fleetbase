# Construction Site Traffic Dispatch System

基于 Fleetbase 平台的工地交通调度系统扩展。

## 功能

- **车辆 GPS 追踪** — 司机通过手机网页自助登记进场，浏览器实时上报 GPS 位置
- **工程车辆分类管理** — 土方/渣土车、混凝土车、材料运输车、工程机械、管理车辆
- **工地道路管理** — 道路、路段、出入口的 CRUD 与地图展示
- **路况信息管理** — 道路开闭状态、路面状况、拥堵情况、临时事件
- **施工活动管理** — 施工活动与影响路段关联，状态变更自动通知
- **站内实时通知** — 道路状态变更、事件新建/解除、施工进度、车辆进场等自动推送到管理台

## 依赖

- Fleetbase 平台（Docker 全栈部署）
- fleetops 扩展（车辆/司机/GPS 追踪/实时地图）

## 安装

### 1. 通过 Composer 安装后端

```bash
cd api
composer require fleetbase/construction-site-api
```

### 2. 运行数据库迁移

```bash
php artisan migrate
```

### 3. 加载示例数据（可选）

```bash
php artisan db:seed --class="Fleetbase\ConstructionSite\Database\Seeders\ConstructionSiteSeeder"
```

### 4. 前端 Engine

前端 Ember Engine 通过 `fleetbase-extensions-generator` 自动发现并挂载到 Console 路由 `/construction-site`。

## API 端点

### 管理台 API（需 fleetbase.auth 鉴权）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET/POST | `/construction-site/int/v1/roads` | 道路 CRUD |
| GET/POST | `/construction-site/int/v1/road-segments` | 路段 CRUD |
| GET/POST | `/construction-site/int/v1/gates` | 出入口 CRUD |
| GET/POST | `/construction-site/int/v1/road-conditions` | 路况 CRUD |
| GET/POST | `/construction-site/int/v1/road-events` | 道路事件 CRUD |
| PATCH | `/construction-site/int/v1/road-events/{id}/resolve` | 解除事件 |
| GET/POST | `/construction-site/int/v1/construction-activities` | 施工活动 CRUD |
| POST | `/construction-site/int/v1/construction-activities/{id}/segments` | 关联影响路段 |
| GET/POST | `/construction-site/int/v1/vehicle-meta` | 车辆工程属性 CRUD |
| GET | `/construction-site/int/v1/entries` | 进场登记列表 |
| PATCH | `/construction-site/int/v1/entries/{id}/exit` | 退场操作 |
| GET | `/construction-site/int/v1/notifications` | 通知列表 |
| GET | `/construction-site/int/v1/notifications/unread-count` | 未读数 |
| PATCH | `/construction-site/int/v1/notifications/{id}/read` | 标记已读 |
| POST | `/construction-site/int/v1/notifications/read-all` | 全部已读 |

### 司机端 API

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/construction-site/int/v1/tracking/register` | 自助进场登记（返回 session_token） |
| POST | `/construction-site/int/v1/tracking/update` | GPS 上报（需 Bearer token） |
| POST | `/construction-site/int/v1/tracking/exit` | 退场（需 Bearer token） |
| GET | `/construction-site/int/v1/tracking/status` | 查询会话状态（需 Bearer token） |

## 司机手机网页使用说明

部署时设置 `CONSTRUCTION_SITE_DEFAULT_COMPANY_UUID`，将司机登记归入指定组织。

1. 司机用手机浏览器打开受信任的 HTTPS 地址 `/driver-track`
2. 填写车牌号、车辆类型、姓名、手机号、用途
3. 点击"登记并开始追踪"
4. 允许浏览器获取位置权限
5. 保持页面打开，系统自动每 5 秒上报 GPS 位置
6. 离场时点击"退场"按钮

## 管理台页面

| 路径 | 说明 |
|---|---|
| `/construction-site` | 总览仪表盘 |
| `/construction-site/live-map` | 实时地图 |
| `/construction-site/roads` | 道路管理 |
| `/construction-site/road-conditions` | 路况记录 |
| `/construction-site/road-events` | 道路事件 |
| `/construction-site/construction` | 施工活动 |
| `/construction-site/vehicles` | 车辆列表 |
| `/construction-site/notifications` | 通知中心 |

## 技术栈

- **后端**: PHP 8 / Laravel 10 / MySQL / Redis / SocketCluster
- **前端**: Ember.js / Tailwind CSS
- **部署**: Docker

## 许可证

继承 Fleetbase 的 AGPL-3.0 许可证。
