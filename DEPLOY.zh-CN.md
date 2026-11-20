# DDmaps Fleetbase 新电脑部署

从 GitHub 克隆仓库，或下载源码 ZIP 后解压。进入包含本文件的目录执行命令。各扩展的源码随仓库提供，无需初始化 Git 子模块。

这是联网构建的源码交付包。数据库、现有管理员、GCC 区域/道路/管制、上传文件、依赖目录和 Docker 镜像不在包内。按下文部署得到独立的新系统；迁移现有地图和业务数据另见第 7 节。

## 1. 包内结构与排除内容

| 路径 | 用途 |
| --- | --- |
| `console/` | Ember 管理前端、路由、翻译、公开静态资源、构建配置及 `pnpm-lock.yaml` |
| `api/` | Laravel 入口、应用配置、异常处理、公共资源、数据库代码及 Composer 清单/锁文件 |
| `packages/` | 工地调度和 FleetOps、共享 Ember/UI、核心 API 等已检出的扩展源码及测试 |
| `docker/api/`、`docker/httpd/`、`docker/socket/` | API 镜像补丁、API 反代、实时消息服务 |
| `compose.portable.yml` | 本包专用的全新电脑部署配置；不会读取旧的本机 override |
| `portable/` | 初始化、部署、首次管理员创建、配置模板及重复打包脚本 |
| `scripts/`、`.github/`、`docs/`、`infra/`、`builds/` | 项目脚本、回归测试工作流、参考说明、Helm 模板及其他平台构建源码；新机以本文流程为准 |

排除 `node_modules`、`vendor`、pnpm 缓存、前端 `dist`、临时目录、运行日志、编译二进制、Git 元数据、浏览器测试产物、旧数据库目录、备份、上传内容、Laravel 运行缓存、容器状态备份、本机 override、账号凭据、实际 `.env`、TLS 私钥/证书、旧的手工热补丁及登录调试文件。保留 `console/public`、`api/public`、扩展的 `public` 和 `server/data` 等源码静态资源。

归档保留根目录原有 Compose 供参考，但新机**只使用 `compose.portable.yml`**。包内 `.dockerignore` 补充允许 `api/public` 进入构建上下文，满足 httpd 镜像的 COPY，并继续排除运行密钥。`api/storage` 和 `api/bootstrap/cache` 仅提供空目录骨架。源码中已嵌入的 CARTO 底图 key 在交付副本中移除，保留原始底图 URL；新 key 通过构建 secret 提供。当前电脑的配置和源文件未因此替换。

## 2. 环境和版本

推荐路线是 Docker Linux 容器，宿主机不需要另外安装 PHP、Composer、MySQL、Redis、Node 或 pnpm。Windows 使用 Docker Desktop 的 WSL2/Linux containers 模式；Linux 使用 Docker Engine 和 Compose v2 插件。命令在普通终端/PowerShell 执行；Linux 如无 `python`，将命令中的 `python` 替换为 `python3`。

| 项目 | 本源码记录的版本/要求 |
| --- | --- |
| 目标平台 | 优先 x86-64 / amd64；API 和 Socket 显式使用 `linux/amd64`，ARM 机器需模拟运行，未实测 |
| Docker Compose | v2，须支持 `--wait`、`config --quiet`、profiles 和 build secrets |
| Python | 3.10+，部署和打包助手只使用标准库，无 pip 依赖 |
| 前端构建 | Dockerfile 固定 Node `22.22.0-alpine3.22`、pnpm `11.0.9`、Ember CLI `5.4.2` |
| 前端依赖 | `console/package.json` + `console/pnpm-lock.yaml`；构建执行 `pnpm install --frozen-lockfile` |
| API | Laravel 10；`api/composer.json` 声明 PHP `>=8.0 <=8.2.31`，工地扩展要求 `^8.1`；部署沿用固定摘要的官方 API 基础镜像，不以宿主机 PHP 版本替代 |
| API 基础镜像 | `fleetbase/fleetbase-api:latest@sha256:23845396576416f0929864340c49c743f2b01f100a84c39d1cbe3e8297a355a0` |
| 后端依赖清单 | `api/composer.json` / `api/composer.lock` 及各扩展的 Composer 文件；部署用基础镜像已装依赖并注册本地工地扩展 |
| 数据库 / 缓存 / 实时通信 | `mysql:8.0-oracle` / `redis:4-alpine` / `socketcluster/socketcluster:v17.4.0` |
| 网关 | 前端 Nginx 按现有 Dockerfile 固定摘要；API httpd 使用 `nginx:stable-alpine` |

**版本还原边界：** API 部署依赖上述基础镜像的可下载性；它不是从本包执行一次纯 `composer install` 构建全部基础平台。前端按照锁文件安装官方扩展，再覆盖 Dockerfile 列出的本地定制；包中包含其余扩展源码，但不自动全部切换为本地链接。MySQL、Redis、httpd 等部分镜像标签尚未按摘要固定，因此这是源码/锁文件可还原交付，不声称所有外部组件逐位一致。不要在部署时执行 `composer update` / `pnpm update` 或删除锁文件。

首次构建需访问 Docker Hub、npm registry、GitHub 与镜像中的依赖来源；运行时地图、道路预览、路径规划还会访问 OSM/Overpass/OSRM 等外部服务。源码包不包含离线地图服务或离线镜像。构建较重，建议给 Docker 留出至少 8 GB 内存和 40 GB 可用空间；这是构建余量建议，不是司机并发容量结论。

## 3. 新电脑快速部署

### 3.1 获取源码并确认工具

通过 Git 获取源码并确认工具版本：

```powershell
git clone https://github.com/Ximutiannuo/DDmaps-Fleetbase.git
cd DDmaps-Fleetbase
docker version
docker compose version
python --version
```

也可以从 GitHub 下载源码 ZIP，解压后进入项目目录。首次执行先启动 Docker 服务。后续命令均在该项目根目录运行。

### 3.2 生成新环境配置

```text
python portable/deploy.py init
```

这一步**不启动容器、不连接旧数据库**，生成以下本机文件：

- `.env`：独立的数据库 root 密码、DML 应用账户密码、Socket 签名密钥和站点设置。
- `api/.env`：独立的 Laravel APP_KEY，关闭 Debug，使用生产环境；默认邮件写日志。
- `portable/generated/mysql-init/001-users.sql`：仅在空数据卷第一次启动时创建应用账户与 storefront 库。应用账户只有 SELECT/INSERT/UPDATE/DELETE，迁移使用独立维护容器中的 root。
- `portable/generated/carto-api-key.txt`：可选的 CARTO key 文件，初始为空。留空时，前端构建会用无需密钥的 OpenStreetMap 标准瓦片替代默认 CARTO 底图，并显示来源标注。公开瓦片适用于按需浏览，使用时需遵守 [OpenStreetMap 瓦片使用政策](https://operations.osmfoundation.org/policies/tiles/)。
- `portable/generated/tls/`：新机自己的证书目录。

密钥不会打印到终端；Windows 对上述密钥文件设置当前用户和 SYSTEM 的文件访问权限，类 Unix 系统设置 0600。重复 `init` 遇到现有配置会拒绝覆盖，避免无意轮换密码。

首次仅在本机验证可以保持默认站点地址。司机使用 `/driver-track` 自助登记。首次创建公司后，在 `api/.env` 中将 `CONSTRUCTION_SITE_DEFAULT_COMPANY_UUID` 设为该公司 UUID。若需要给登记绑定固定门岗，可将 `CONSTRUCTION_SITE_DEFAULT_GATE_UUID` 设为该公司的门岗 UUID。配置后重新启动 API 服务。

局域网使用前，将根 `.env` 中的 `CONSOLE_HOST` 改为 `https://新电脑IP或域名:4200`，`SESSION_DOMAIN` 改为对应的主机名/IP（不含协议和端口）；`api/.env` 的 APP_URL / CONSOLE_HOST 同步填写。

如下载需要代理，在根 `.env` 填写 `BUILD_HTTP_PROXY`、`BUILD_HTTPS_PROXY`，例如 Docker Desktop 可访问的 `http://host.docker.internal:实际端口`；默认为空，不依赖旧机器的 7897 端口。Linux 代理需填写容器可访问的地址。Docker 守护进程拉取镜像的代理由 Docker 自身设置管理，这两个变量只供前端构建步骤使用。

### 3.3 安装依赖、构建、迁移、创建管理员并启动

```text
python portable/deploy.py install
```

流程依次为：检查 Compose → 构建 API/console/httpd → 启动 MySQL/Redis/Socket 并等待 → 使用维护容器迁移 → 交互输入公司名称、管理员姓名、邮箱及新密码 → 创建权限/管理员角色 → 启动其余服务。密码至少 12 个字符，输入不回显、不放入命令行参数；包内没有默认管理员密码。**首次管理员入口只接受 users/companies 都为空的数据库，不会重置已有账号。**

依赖安装和前端构建在 Dockerfile 中自动完成；宿主机无需再执行 npm/composer 安装。正常终端需要能交互输入管理员信息。若安装停在某一步，按下节分步继续，不要删除数据库卷或反复初始化。

本机先访问 **`http://localhost:4201`** 检查登录、工地调度、道路预览和通知；此 HTTP 端口只绑定本机。HTTPS 和手机访问使用 4200，先完成第 5 节证书配置。不要将临时自签名证书当作手机 GPS 已可用的验收证据。

## 4. 分步命令、日常启动及故障排查

所有命令由助手显式指定项目名 `ddmaps-portable` 和 `compose.portable.yml`，不会意外合并旧 override。等价前缀为 `docker compose --env-file .env -p ddmaps-portable -f compose.portable.yml`。

```text
# 检查配置（quiet 避免把展开后的密码输出到屏幕）
python portable/deploy.py compose config --quiet

# 安装依赖并构建镜像
python portable/deploy.py compose build application console httpd

# 空数据库首次启动并等待就绪
python portable/deploy.py compose up -d --wait --wait-timeout 240 database cache socket

# 执行主库/扩展迁移，仅维护容器使用 DDL 权限
python portable/deploy.py compose run --rm --no-deps maintenance php artisan migrate --force

# 空库首次创建管理员（已创建过则不要重跑）
python portable/deploy.py admin

# 启动全部日常服务；已有安装以后直接使用此命令
python portable/deploy.py compose up -d --wait --wait-timeout 240

# 状态、日志、迁移状态
python portable/deploy.py compose ps
python portable/deploy.py compose logs --tail 100 application queue scheduler socket console
python portable/deploy.py compose run --rm --no-deps maintenance php artisan migrate:status

# 停止；保留命名数据卷
python portable/deploy.py compose down
```

若首次管理员已创建但权限生成失败，在维护窗口执行下面两条后启动服务；它们会重建系统权限配置，不能作为平时每次启动步骤：

```text
python portable/deploy.py compose run --rm --no-deps maintenance php artisan fleetbase:create-permissions
python portable/deploy.py compose run --rm --no-deps maintenance php artisan fleetbase:assign-admin-roles
```

若 4200/4201 被占用，先停掉占用它们的旧实例；本包使用默认端口验证流程。数据库/Redis/Socket 不发布宿主机端口。首次 SQL 仅在空 MySQL 卷自动执行；之后编辑 `.env` 的数据库密码不会自动改变数据库内账户。不要用 `down -v` 排障，它会删除这套新安装的数据库、Redis 和上传卷。

更新源码后，先备份数据，再运行 build、维护迁移和 up；前端强制刷新浏览器。可用 Git 提交号记录当前部署使用的源代码版本。

## 5. HTTPS、局域网与手机 GPS

前端容器首次启动会在 `portable/generated/tls` 生成仅用于启动的 localhost 自签名证书。正式局域网/手机使用应准备包含新 IP/域名 SAN 且被设备信任的证书。将服务器证书与私钥分别保存为：

```text
portable/generated/tls/cert.pem
portable/generated/tls/key.pem
```

证书可来自可信 CA，或由你管理的内部 CA 签发；内部 CA 的公钥证书需要在电脑/手机的系统设置中信任。不要复制旧机器的 CA 私钥，不要将私钥分发给手机。替换后执行：

```text
python portable/deploy.py compose restart console
```

给新主机配置固定地址或稳定 DNS，并按使用范围开放 TCP 4200。访问 `https://新电脑IP或域名:4200`，司机页为 `/driver-track`。手机 GPS 还需要受信任 HTTPS、定位授权和实际定位信号；HTTP 回环验证不能替代实机验收。

原 `scripts/configure-tls.ps1` / `initialize-security.ps1` / `start.ps1` 包含旧项目容器名或旧数据库迁移假设，**不要用于本包的新机流程**；同样不要直接运行根目录旧 `start.bat`。

## 6. 开发依赖、校验与重新打包

使用 Docker 构建是部署入口。若仅做前端开发，可安装与 Dockerfile 相同的 Node 22.22.0、pnpm 11.0.9，进入 `console` 执行 `pnpm install --frozen-lockfile`。生产前端使用 Dockerfile 的本地扩展注册与共享包补丁；单独 `pnpm build --environment production` 不会自动重现这些覆盖步骤。

原生 PHP 开发先检查 `api/composer.json`、`api/composer.lock`、扩展平台约束和所需 PHP 扩展，再在 `api` 执行 `composer install --no-interaction --prefer-dist`。这不是经本包验证的生产替代路线。不要使用 `--ignore-platform-reqs` 隐藏版本冲突。

现有独立回归入口保留在 `console/tests`、`scripts/regression.sh`、`docker/socket/security-test.js` 和 `docker-compose.test.yml`。这次打包没有把历史运行结果当作新机器已实测结果。

从当前解压目录重新生成纯源码包：

```text
python portable/package-source.py --source . --output ../DDmaps-Fleetbase-source-custom.zip
```

脚本包含所有实际工作区/子模块源码，按规则排除依赖、运行数据、密钥和产物，扫描当前本机凭据和私钥，写入逐文件清单、验证 ZIP CRC，并生成旁置 `.zip.sha256`。已有输出文件会拒绝覆盖。源码包仍不包含镜像；首次构建依赖外部 registry，可用性受目标网络影响。

Windows 可用 `Get-FileHash -Algorithm SHA256 .\DDmaps-Fleetbase-source-2026-11-14.zip` 校验收到的压缩包。Linux 可用 `sha256sum -c DDmaps-Fleetbase-source-2026-11-14.zip.sha256`。

## 7. 如需迁移当前 GCC 和业务数据

本包是源码，不含当前电脑的数据库和上传文件。需要同样的 GCC、道路、管制及账号时，另行制作一致的数据库逻辑备份和上传文件备份，在目标空库恢复；不要复制正在运行的 MySQL 数据目录，也不要把旧备份混入可分享的源码包。

迁移方案应先确认源实例/数据库范围、备份时点及恢复演练；涉及 storefront/sandbox 的实际业务还要覆盖对应库。目标恢复完数据后跳过“首次创建管理员”，保留原账号和权限，使用目标环境配置启动。若有 Laravel 加密持久字段，需要安全迁移原 APP_KEY；单纯生成新 key 不能解密旧数据。数据库账户和 Socket 密钥可以在目标重新配置，不能将它们与 APP_KEY 的用途混淆。

## 8. 本次交付校验边界

已检查项目源码结构、构建输入、锁文件和排除规则；验证 Python 脚本语法与空目录初始化/重复初始化保护，PHP 管理员入口语法、Compose 配置解析、ZIP 完整性及文件 SHA-256。现有 Docker 引擎未启动，**本次未实际拉取基础镜像、全量重建或在空容器中完成新机启动**。首次实际部署仍应按第 3–5 节检查登录、数据库迁移、地图、导入、实时通知和手机定位。此说明不将过去在旧电脑的运行验收视为本次新机验收。
