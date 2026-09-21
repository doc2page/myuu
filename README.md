# myuu

自动转移做种客户端：把种子从一个下载器（qBittorrent / Transmission）迁移到另一个，本地数据不重新下载，只重新挂种。

自 [IYUUPlus](https://github.com/ledccn/iyuuplus-dev) 的「自动转移」功能抽离的**纯本地版**。

## 安全声明

**本工具唯一的外部网络流量 = 你自己配置的下载器 API 请求。**

- 不含 IYUU 官网 API、辅种、站点交互、WebSocket、通知推送等任何外联功能
- 不引入 `iyuu/*`、`ledc/*` 任何代码，HTTP 层为项目内自写的 ~200 行 curl 封装
- 唯一 composer 依赖 [`rhilip/bencode`](https://packagist.org/packages/rhilip/bencode)（纯 bencode 编解码，无网络行为）
- 逐行裁剪移植，可直接审查：`src/` + `bin/` 全部源码约 1700 行

## 功能

- 源/目标支持 qBittorrent 与 Transmission 任意组合（跨客户端、跨机器）
- 路径过滤器 / 选择器（前缀匹配，过滤掉不想迁移的目录）
- 路径转换（`replace` 替换前缀 / `add` 加前缀 / `sub` 去前缀 / `eq` 原样）
- 可选：跳校验（`skip_check`）、暂停入种（`paused`）、转移成功后删除源做种（`delete_torrent`，不删文件）
- 转移后给种子打分类/标签（`marker`，默认不打标）
- SQLite 去重缓存：增量转移，已处理过的种子（含失败）自动跳过
- 保留 IYUU 多年踩坑逻辑：qB ≥4.4 的 v2 种子按 `infohash_v1` 定位种子文件、种子缺 tracker 时从 `.fastresume` 补、Tr 种子文件路径缺失时用 BT_backup 兜底

## 部署（群晖 / 任意 Docker 宿主）

```bash
git clone <本仓库> myuu && cd myuu
docker compose up -d --build
```

首次启动会自动在 `config/` 下生成 `config.json`（内置示例），编辑下载器地址与路径映射后重启容器即可。

日志：`docker logs -f myuu`

试运行（不推送、不写缓存）：

```bash
docker compose run --rm myuu --dry-run
```

### 关键前提：挂载 BT_backup

转移需要**读取源下载器的种子文件**（`.torrent`），必须把源下载器的种子目录挂载进本容器（只读）：

| 源下载器 | 挂载的宿主路径 | 对应配置字段 |
|---|---|---|
| qBittorrent | `<qB配置目录>/BT_backup` | `from.torrent_path`（容器内路径） |
| Transmission | `<tr配置目录>/torrents` | `from.torrent_path`（容器内路径） |

在 `docker-compose.yml` 的 volumes 里加（示例）：

```yaml
- /volume1/docker/qbittorrent/BT_backup:/bt_backup/qbittorrent:ro
```

并将任务配置的 `from.torrent_path` 设为 `/bt_backup/qbittorrent`。

目标下载器**无需**挂载（种子经 Web API 推送过去），但目标机器上必须已存在对应数据文件（路径由 `path_convert_rule` 映射）。

## 配置说明

`config/config.json`：

| 字段 | 说明 |
|---|---|
| `interval` | 常驻模式轮询间隔（秒，默认 3600，最小 60） |
| `data_path` | SQLite 缓存路径（默认 `/app/data/transfer.sqlite`） |
| `tasks[].name` | 任务名（唯一，用于缓存隔离与命令行指定） |
| `tasks[].from` / `to` | 下载器：`type`（`qbit`/`transmission`）、`url`、`username`、`password`；`from` 另需 `torrent_path`；`to` 可选 `root_folder`（qB） |
| `tasks[].path_filter` | 路径过滤器（前缀，命中即跳过，优先级高） |
| `tasks[].path_selector` | 路径选择器（前缀，命中才转移；与 filter 同时设置时先 filter 后 selector） |
| `tasks[].path_convert_type` | `eq`（默认）/ `add` / `sub` / `replace` |
| `tasks[].path_convert_rule` | `[{ "from": "源前缀", "to": "目标前缀" }]` |
| `tasks[].skip_check` | 转移后跳校验（默认 false） |
| `tasks[].paused` | 以暂停状态添加（默认 false） |
| `tasks[].delete_torrent` | 转移成功后删除源做种，**不删数据文件**（默认 false） |
| `tasks[].marker` | `{ "type": "none\|category\|tag\|labels", "name": "..." }`：转移后打的分类/标签；qB 支持 category/tag，Tr 为 labels；默认 none |

路径转换语义（前缀匹配第一个命中生效）：

- `replace`：`from` 前缀替换为 `to`（最常用，跨机器路径映射）
- `add`：路径前直接拼接 `to`
- `sub`：去掉 `from` 前缀（转相对路径）
- `eq`：原样

## 命令

| 命令 | 说明 |
|---|---|
| `docker compose up -d` | 常驻模式 |
| `docker compose run --rm myuu --once` | 执行一轮退出 |
| `docker compose run --rm myuu --dry-run` | 试运行（只打印将要转移什么，不推送不写缓存） |
| `docker compose run --rm myuu --task <name>` | 只跑指定任务 |
| `docker compose run --rm myuu --reset <name>` | 清空任务缓存（改了转换规则想重跑时用） |

## 行为说明

- 与 IYUU 一致：**失败的种子也会进缓存**，不会反复重试；修正配置（如路径映射）后用 `--reset <name>` 清缓存重跑
- 只迁移「正常做种状态」的种子（qB：uploading/stalledUP/pausedUP 等；Tr：status=6）
- `delete_torrent` 只删源下载器里的种子任务，不删任何数据文件

## 来源与许可

- 核心转移逻辑自 [ledccn/iyuuplus-dev](https://github.com/ledccn/iyuuplus-dev)（MIT）的 `app/admin/services/transfer/TransferServices.php` 与 `composer/bittorrent-client/` 裁剪移植
- 本项目 MIT，见 [LICENSE](LICENSE)
