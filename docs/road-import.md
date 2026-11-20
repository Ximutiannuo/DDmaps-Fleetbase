# 从地图导入 GCC 道路

在 FleetOps 画好服务区域后，进入工地调度 → 实时地图，选择该 GCC，再点击 **自动生成路段**。系统批量导入边界内可用的机动车道路，按真实交汇点和道路端点分段，生成后回到路况面板。限制通行道路按封闭导入，已有、删除或重叠的道路由现有去重和恢复规则处理。

需要先筛选道路时，可使用 **从地图导入道路** 预览并勾选候选。

系统通过 Overpass 读取 OpenStreetMap 的道路矢量与节点，不识别底图图片中的像素。获取时仅向道路服务发送所选区域的查询范围，不上传司机定位、车辆和路况设置。导入后道路保存在本地数据库，定位统计、路况刷新和导航不再查询 Overpass。

## 使用流程

1. 获取当前 GCC 的候选道路，查看地图预览。紫色虚线表示已勾选；橙色表示尚未勾选、需核实通行权限但可导入的道路，点击后变为紫色；灰色短虚线表示已有、已删除或重叠。已导入道路可点击进入对应分段的路况编辑；已删除道路可点击打开“恢复原道路”确认；重叠候选点击会说明原因。普通未选道路为灰色长虚线；单行箭头随道路颜色变化。虚线有连续的宽点击范围，点在虚线间隙或附近也能选中；多个点击范围重叠时按光标位置匹配最近候选，几何完全重合时优先可导入候选。
2. 用道路类型与“全部候选”“可导入道路”“需核实通行权限”“已存在／重叠道路”组合筛选。点击“一键勾选可导入道路”勾选当前筛选下的全部新道路，包含限制道路；“仅勾选非限制道路”则排除限制道路。“清空当前筛选”仅取消当前可见候选，其他筛选下的选择保留。逐条勾选与地图点击同步，确认按钮的数量只包含当前筛选下的选中道路。
3. 点击“确认导入选中的 … 条道路”后才写入路网。取消预览不会创建道路。
4. 地图上方可切换“编辑路况”和“勾选导入”。编辑模式隐藏导入预览的点击层，保留已勾选内容；点击实线选择光标位置最近的路段，圆点打开交汇点编辑。交汇点圆点位于预览层之上，在勾选模式也可点击。当前没有新候选时默认进入编辑模式。
5. 导入后点击路段，在路况面板选择“修改道路名称”。名称作用于整条原道路，各分段及交汇点标签同步刷新；保存路况只作用于当前选中的两个交汇点之间的一段。无需先在道路模型列表中找到该原道路。OSM 没有收录的道路仍可手绘补充。
6. 整条道路软删除后，OSM 来源标识仍保留，普通导入和一键勾选不会重复创建道路。请在预览候选旁或右侧“已删除整条道路”中点击“恢复原道路”，核对后确认。恢复使用原 UUID、名称、几何、单行规则和路况；此前单独删除的分段仍需在“已删除路段”中单独恢复。

可获取主干道、居民道路、普通道路、服务道路、便道和施工道路。步行道路及明确禁止机动车的道路被排除。私有／目的地专用、货车限制、限高限宽限重、条件通行和无法直接转换的单行规则需管理员核实：预览默认不勾选；逐条或一键勾选后可导入，导入后默认封闭，再通过路况编辑器开放。页面显示当前已选限制道路数。施工道路按施工禁行导入。

重叠道路不可直接勾选导入。检查会考虑道路方向和沿线距离，避免新增一条开放的平行几何绕过现有封路。重叠检查采用保守策略，也可能把很近的独立平行道路标为待核对；本版本不自动合并、替换或重新放开这些道路。

## 路网与数据规则

- 道路沿 GCC 的 Polygon／MultiPolygon 裁剪，排除孔洞和区域外部分，保留 OSM 真实节点 ID。
- 两条导入道路仅在共享节点连接；几何上的交叉不直接视为路口。桥梁、隧道和层级信息保留，避免立交误连；共享节点允许坡道连接。
- 手绘道路沿用已有吸附规则；与导入道路只在同层的实际几何交汇处连接。手绘端点与 OSM 道路存在偏差时应核对几何，必要时调整端点。
- 支持正向／反向单行、普通双向、环岛及高速公路的默认单行。起终点投影到具体道路边，不能通过吸附邻近节点跳过单行限制；导入路网的接入距离上限为 100 米。
- 拓扑分段依据物理连接，独立于单行方向。普通弯道节点保留在段内；司机规划使用同一份分段、通行方向和路况成本。
- 同公司使用来源标识去重。已删除道路不会因重新获取而自动恢复；已有导入道路不被覆盖，以保留管理员修改。OSM 数据变更需要人工核对，本版本不自动同步更新已有道路几何。
- 新导入道路改变交汇结构时，拆分后的已有路段继承原分段的通行、手动拥堵和阈值设置；已有交汇点换成 OSM 节点标识时也尽量按同层、同位置和共同父道路继承。普通弯道编辑不改变导入道路两端标识，保留该段设置。
- 获取数据缓存 1 小时，预览有效 15 分钟。提交校验公司、GCC、边界版本和服务端预览，并在事务内重新检查重复及重叠。丢失提交响应后可安全重试。
- 单次区域包围盒不超过 100 平方公里，最多 1,500 个候选，外部连接超时 10 秒、请求超时 45 秒。服务异常、查询不完整及无道路覆盖均给出反馈。
- 批量导入提交后失效道路列表缓存并发出一次路网变更事件，供司机端重新获取与规划。

当前导航仍采用工地基础速度和拥堵成本估算通行时间。OSM 转向限制关系、车型尺寸／重量的逐车校验、可逆车道时段及 GPS 高程识别不在本次实现范围；相关数据或标签需现场核对。OSM 未收录的厂内临时道路不会凭空生成。

数据来源与许可：[OpenStreetMap / ODbL](https://www.openstreetmap.org/copyright)。查询与方向规则参考 [Overpass QL](https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL) 和 [OSM oneway](https://wiki.openstreetmap.org/wiki/Key:oneway)。页面保留来源署名及许可链接。

## 接口与部署

- `POST construction-site/int/v1/road-imports/preview`：`service_area_uuid`；返回候选、预览令牌、获取时间和过期时间。
- `POST construction-site/int/v1/road-segments/{uuid}/restore`：`service_area_uuid`、`expected_geometry`；仅恢复当前公司、原 GCC 的道路，重复请求幂等，保留原记录与配置。几何已改变时返回 409。恢复前重新核对现有路网，若新增平行道路与原道路重叠则返回 422，防止形成绕过封闭设置的重复路径。
- 管理员 `GET construction-site/int/v1/traffic` 返回当前 GCC 的 `deleted_roads`；司机接口不含此恢复列表。
- `POST construction-site/int/v1/road-imports/commit`：`service_area_uuid`、`preview_token`、`candidate_keys`；返回创建数、跳过数和道路 UUID。
- 新增 `road_segments.source`、`source_key`、`source_meta`、`routing`，唯一索引为公司与来源标识。
- `OSM_OVERPASS_ENDPOINT` 可配置服务端 Overpass 地址，默认 `https://overpass-api.de/api/interpreter`。客户端不能指定地址。

```powershell
docker compose exec -T application php artisan migrate --path=../packages/construction-site/server/migrations/2026_10_08_000002_add_road_import_metadata.php --force
docker compose build application
docker compose up -d --no-deps application queue scheduler
docker compose build console
docker compose up -d --no-deps console
```

```powershell
php api/vendor/bin/phpunit --no-configuration packages/construction-site/server/tests/OsmRoadImportTest.php
php api/vendor/bin/phpunit --no-configuration packages/construction-site/server/tests/RoadTrafficRoutingTest.php
node --test console/tests/road-import.test.cjs console/tests/road-import-map.test.cjs console/tests/traffic-navigation.test.cjs console/tests/gps-uploader.test.cjs
docker compose exec -T application php ../packages/construction-site/server/tests/road-recovery-integration.php
docker compose exec -T application php ../packages/construction-site/server/tests/road-import-integration.php
docker compose exec -T application php ../packages/construction-site/server/tests/traffic-integration.php
```

导入集成测试使用模拟 Overpass 响应及真实 Laravel／MySQL，在事务内验证保存、重复、公司隔离、边界变更、过期预览、限制道路、已有配置继承和管理员编辑，结束时回滚业务数据并清理测试缓存。真实 GCC 的浏览器验证只获取预览。

道路恢复集成测试使用真实 Laravel／MySQL 的回滚事务，验证来源去重、原 UUID 恢复、公司／GCC 隔离、过期几何拒绝、单独删除分段的保留、新交汇点的路况继承、名称及司机路网版本同步。浏览器验证只打开并取消恢复确认和改名表单，不恢复或改名真实道路。
