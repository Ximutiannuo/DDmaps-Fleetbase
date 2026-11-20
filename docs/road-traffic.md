# GCC 道路与交汇点路况

入口为「工地调度 → 实时地图」。选择 GCC 后，点击两个相邻交汇点之间的路段、地图上的交汇点圆点或右侧列表，即可编辑路况。道路末端以端点为界。

## 路况编辑范围

一条绘制道路可以包含多个交汇点。后台按路网连接关系将它拆成独立的路况分段，普通弯道的绘制点仍保留在同一段内。没有交汇点的道路或独立环路保留为一段。

地图只对选中的路段显示蓝色描边，编辑面板和列表显示段号、起止交汇点与长度。每段独立保存通行状态、拥堵覆盖和阈值；车辆数也只统计该段。司机端收到相同的分段几何和状态，导航按各段的时间成本与封闭状态计算。

绘制道路的名称和整体几何仍从「道路几何与名称」编辑。保存分段路况不会修改整条道路的 `status`。之前已有的整条道路状态与拥堵配置作为各段的默认值保留；编辑某段后，该段自己的设置优先。

手绘分段标识为 `section:<绘制道路 UUID>:<分段几何哈希>`，保存对应父道路 UUID 和该段 LineString。重画手绘道路或手动增加交汇点改变分段几何时，旧选择的保存请求返回 404，前端刷新后要求重新选择路段，应重新检查分段设置。[地图道路导入](road-import.md)另有来源与节点元数据：导入产生的新分段会继承被拆分路段的已有设置，导入道路使用两端节点标识保持普通弯道编辑后的分段设置。

通行状态与拥堵等级独立：可通行、部分封闭、施工、封闭；自动拥堵等级为数据不足、通畅、轻微、中度、严重、停滞。施工与封闭禁止通行；部分封闭仍可减速通过。交汇点由已有路网的共享端点、T 型连接和交叉连接自动识别。

## 删除与恢复路段

点击地图或右侧列表中的一段道路，在「路况编辑」面板点击「删除此路段」，核对起止交汇点、长度后确认。只移除该分段，相邻路段及其路况设置保留。该段从管理员和司机的道路状态图层中隐藏，并从可通行路网中排除；导航不可通过端点吸附跨越缺口。地图底图的 OSM 道路仍是底图内容，不会被修改。

删除后可点击「撤销上次路段删除」。刷新页面后，右侧「已删除路段」仍可恢复，恢复范围包括后续导入交汇点拆出的子分段，原通行状态、拥堵设置、阈值和备注保留。交汇点会按仍存在的连接更新；删除记录保留原范围边界，其他相交道路被删除也不会把缺口重新合并进开放路段。

「管理整条道路」可打开名称与几何面板，其中的「删除整条道路」会移除父道路及其全部分段，单独显示整条删除确认。该操作沿用父道路的软删除，不在分段恢复列表中提供恢复。临时施工／禁行建议直接设置「施工」或「封闭」。含已删除分段的父道路需要先恢复分段，再重画整体几何，名称仍可编辑。

分段删除使用 `road_traffic_controls.is_removed / removed_at / removal_origin_key` 保存删除记录，原路况字段不被覆盖。内部快照保留删除几何用于禁止端点吸附，外部管理员／司机图层过滤该段；管理员快照另有 `removed_sections`，司机接口不包含恢复记录。删除、恢复、导入、父道路编辑与路况保存按公司锁串行执行，删除请求校验客户端看到的精确几何，拒绝范围已改变的旧选择。路网变更消息在数据库提交后发送。

## 自动统计与手动覆盖

- 只统计本公司有效进场会话，且仍开启追踪、未退场、最近 90 秒内实际采集的 GPS。旧客户端没有采集时间时兼容使用服务器接收时间。
- 没有有效样本时显示灰色“数据不足”，并区分“暂无有效定位”和“定位已过期”。过期样本不参与车辆数与平均车速。手动拥堵设置仍生效，自动估计与数据状态独立保留。
- 排除精度未知或大于 50 米的定位。车速单位为米／秒。
- 每辆车只匹配 25 米内最近的一段道路，同一车辆的重复会话不会重复计数。
- 交汇点单独统计 30 米内的车辆，车辆可以同时属于一条道路和一个交汇点。
- 默认轻微、中度、严重拥堵阈值依次为 3、6、10 辆。每个路段与每个交汇点可以分别配置，阈值必须递增。
- 达到轻微阈值且至少有 3 份车速时，平均车速 ≤ 2 米／秒提高到严重拥堵，≤ 0.5 米／秒判定停滞。没有车速时只按数量判定。
- 手动模式保持管理员设置，同时保留自动估计与现场计数。切回自动即解除覆盖。

管理员地图和司机页面每 15 秒刷新路况。手动路况变更沿用公司的路网变更消息，司机端收到消息后刷新地图与路线。定时刷新同时处理自动等级变化与离线定位过期。

## 路线计算

后台按预计通行时间运行 Dijkstra。基准车速沿用现有工地路网的 20 km/h；拥堵系数依次为 1、1.3、2、4、5，部分封闭另外乘 1.5。交汇点等待时间依次为 0、10、30、60、120 秒；部分封闭交汇点额外等待 30 秒。

数据不足时使用基础通行时间估算，不能据此判断道路实际通畅。施工、封闭、禁行和手动覆盖仍按原规则参与规划。

导航步骤按行驶顺序累计完整路段，普通弯道的绘制点不会提前结束当前步骤。换段／通过分叉路口时根据行驶方向识别直行、左转、右转及掉头。接口返回每个有序路段的行驶时间与路口等待时间；司机端随位置更新剩余距离、下一步距离和剩余时间，驶过的路口等待不再计入。

施工／封闭交汇点阻断交汇处 15 米半径，保留来路可通行部分。线路仍遵守现有禁行线／面。没有可达路线时接口返回 422；司机端清除已失效的路线，显示无法到达的原因。

位于施工／封闭路段内部的起点或终点不会通过导航的端点吸附跳到旁边的开放路段；与可通行路段共享的交汇端点仍可到达。

司机在道路中间的位置按线段投影计算偏航与行程进度，避免路网节点稀疏导致误报偏航。GPS 至道路或道路至目的地的连接段保留为灰色虚线。

司机地图采用固定的 Leaflet 图层顺序：区域底图（默认 overlay）、道路／交汇点／禁行状态（450）、导航路线及已行驶／连接段（550）、司机定位与起终点标记（默认 marker 600）。路况每次刷新重绘后，导航仍显示在上方。导航图层不接收鼠标事件，底层道路提示与区域选择仍可操作；主导航线为 6 像素、不透明蓝线。

该模型估计的是当前录入路网与参与上报车辆下的通行时间。未上报车辆、道路容量、单行线、转向限制等仍需另行建模；阈值、系数和等待时间需要结合现场数据校准。当前道路归属 GCC 沿用几何中心判断，交汇点按坐标判断；重画道路导致交汇点位置变化后，应重新检查相应交汇点设置。

## 司机定位上传

司机页分别显示本机定位次数、定位年龄、上传状态与上次服务器确认时间。HTTP 成功且接口返回有效采集时间确认后才显示“服务器已确认定位”；HTTP 500、断网和超时会自动重试，超时为 10 秒，重试间隔逐步增加到最多 30 秒。正常上传间隔至少 5 秒。

待上传队列只保留最新一份定位。超过 90 秒的样本不再补传，恢复网络后上传最新定位；不回放离线轨迹历史。HTTP 401／403 停止重试并提供重新登记入口；HTTP 422 丢弃无效样本、等待下一份定位。

服务器保存 gps_observed_at（实际采集时间）和 gps_reported_at（服务器接收时间），返回 gps_observed_at／gps_received_at。同一会话的上传使用数据库行锁，旧时间戳和重复样本不会覆盖位置或刷新时间；上传期间已退场或禁用追踪的会话返回 401。超过服务器时间 30 秒的样本返回 422。广播在数据库提交后执行。

## 定时任务健康检查

scheduler 使用自己的进程与心跳检查，避免继承 API 的 HTTP metrics 检查而持续误报。每分钟成功执行 artisan schedule:run 后更新 /tmp/fleetbase-scheduler-heartbeat；检查 go-crond 进程仍存在、150 秒内有成功心跳。健康检查确认调度入口执行，不代表每个异步任务的业务结果都成功。

## 代码与接口

- `RoadTrafficService`：定位筛选、匹配、去重、拥堵分级与路况快照。
- `RoadTrafficController`：公司与 GCC 范围验证、阈值验证、保存管理设置。
- `SiteRoadGraphService`：识别交汇点、按拓扑分段、时间成本、禁行与路线计算。
- `GET construction-site/int/v1/traffic?service_area_uuid=…`：管理端快照。
- `POST construction-site/int/v1/traffic-controls`：保存路段或交汇点设置；拒绝整条绘制道路的旧 `road:` 编辑标识。
- `POST construction-site/int/v1/road-sections/remove`：传入 `service_area_uuid`、`target_key`、`expected_geometry`，删除一段道路，可安全重复请求。
- `POST construction-site/int/v1/road-sections/restore`：传入 `service_area_uuid`、原删除 `target_key`，恢复该次删除及继承删除标记的子分段。
- 司机端现有 `tracking/road-network` 和 `tracking/route` 同时使用路况快照。
- 新表 `road_traffic_controls`；`entry_registrations` 新增 GPS 坐标、车速、精度与上报时间。已有司机需重新上报定位才参与统计。

## 部署与验证

```powershell
docker compose exec -T application php artisan migrate --path=../packages/construction-site/server/migrations/2026_10_07_000001_create_road_traffic_controls.php --force
docker compose exec -T application php artisan migrate --path=../packages/construction-site/server/migrations/2026_10_08_000001_add_gps_observed_at_to_entry_registrations.php --force
docker compose exec -T application php artisan migrate --path=../packages/construction-site/server/migrations/2026_10_09_000001_add_road_section_removal.php --force
docker compose exec -T application php artisan route:clear
docker compose build application
docker compose up -d --no-deps application queue scheduler
docker compose build console
docker compose up -d --no-deps console
```

删除功能验证：`php api/vendor/bin/phpunit --no-configuration packages/construction-site/server/tests/RoadSectionRemovalTest.php`、`node --test console/tests/road-section-delete.test.cjs`、`docker compose exec -T application php ../packages/construction-site/server/tests/road-section-delete-integration.php`。数据库集成验证在独立公司和 GCC 夹具中执行，并回滚所有业务数据。

```powershell
php api/vendor/bin/phpunit --no-configuration packages/construction-site/server/tests/RoadTrafficRoutingTest.php
node --test console/tests/traffic-navigation.test.cjs console/tests/gps-uploader.test.cjs
docker compose exec -T application php ../packages/construction-site/server/tests/traffic-integration.php
```

集成脚本在数据库事务中创建专用测试公司、GCC、道路、司机与会话，屏蔽路况广播事件，并始终回滚测试数据。GPS 提交后广播也因事务回滚而不会发送。纯算法与上传重试测试不读取数据库。
