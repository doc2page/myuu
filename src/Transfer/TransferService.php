<?php

declare(strict_types=1);

namespace Myuu\Transfer;

use Myuu\Client\AbstractClient;
use Myuu\Client\ClientFactory;
use Myuu\Contract\Torrent;
use Rhilip\Bencode\Bencode;
use Rhilip\Bencode\ParseException;
use Throwable;

/**
 * 自动转移做种服务（自 IYUU TransferServices 移植）
 *
 * 流程：拉源做种列表 → 去重 → 路径过滤/转换 → 读取种子文件
 * → 推送给目标下载器 → 可选删除源做种 → 记录结果。
 *
 * 保留的 IYUU 踩坑逻辑：
 * - qB ≥4.4 v2 种子用 infohash_v1 定位 .torrent
 * - 种子缺 announce 时从 .fastresume（bencode）补 tracker
 * - Tr 种子文件路径缺失时用 BT_backup 目录兜底
 * - 跨平台路径兼容（rtrim "/\\"）
 */
final class TransferService
{
    private readonly TaskConfig $task;
    private readonly TransferStore $store;
    private readonly PathRule $pathRule;
    private bool $dryRun;

    public function __construct(TaskConfig $task, TransferStore $store, bool $dryRun = false)
    {
        $this->task = $task;
        $this->store = $store;
        $this->pathRule = $task->pathRule();
        $this->dryRun = $dryRun;
    }

    /**
     * 执行转移
     */
    public function run(): void
    {
        $task = $this->task;
        $fromClient = ClientFactory::create($task->from);
        $toClient = ClientFactory::create($task->to);

        // 来源是 qB 时检测版本（≥4.4 需要补 tracker 信息）
        $needPatchTorrent = $this->versionGEQ44($fromClient);

        $this->log("正在从 {$task->from['url']} 获取当前做种 hash ...");
        $torrentList = $fromClient->getTorrentList();
        $hashDict = $torrentList['hashString'];   // 哈希目录字典
        $move = $torrentList[AbstractClient::TORRENT_LIST];

        $fromKey = $task->clientKey($task->from);
        $toKey = $task->clientKey($task->to);
        $stats = ['skip' => 0, 'ok' => 0, 'fail' => 0];

        foreach ($hashDict as $infohash => $downloadDirOriginal) {
            // 纯数字的 infohash 会被 PHP 转为 int 数组键，统一回字符串
            $infohash = (string)$infohash;
            if ($this->store->exists($task->name, $fromKey, $toKey, $infohash)) {
                $stats['skip']++;
                continue;
            }

            if ($this->pathRule->filter($downloadDirOriginal)) {
                $stats['skip']++;
                continue;
            }

            // 做种实际路径与相对路径之间互转
            $downloadDir = $this->pathRule->convert($downloadDirOriginal);
            if (null === $downloadDir) {
                $msg = '路径转换参数配置错误，请重新配置！';
                $this->log($msg);
                $this->store->record($task->name, $fromKey, $toKey, $infohash, [
                    'directory' => $downloadDirOriginal,
                    'message' => $msg,
                    'state' => 0,
                    'last_time' => time(),
                ]);
                return;   // 与原版一致：转换配置错误时终止整个任务
            }

            try {
                $torrent = ($fromClient instanceof \Myuu\Client\TransmissionClient)
                    ? $this->handleTransmission($infohash, (string)($task->from['torrent_path'] ?? ''), $move)
                    : $this->handleQBittorrent($infohash, (string)($task->from['torrent_path'] ?? ''), $move, $needPatchTorrent, $task->paused);
            } catch (Throwable $throwable) {
                $this->log("【读取种子元信息】异常：{$throwable->getMessage()}");
                $this->store->record($task->name, $fromKey, $toKey, $infohash, [
                    'directory' => $downloadDirOriginal,
                    'convert_directory' => $downloadDir,
                    'message' => $throwable->getMessage(),
                    'state' => 0,
                    'last_time' => time(),
                ]);
                $stats['fail']++;
                continue;
            }

            $torrent->savePath = $downloadDir;
            $this->sendBefore($torrent, $toClient);

            if ($this->dryRun) {
                $hasTorrent = '' !== $torrent->payload ? '已读取' : '无';
                $this->log("[DRY-RUN] {$infohash}：{$downloadDirOriginal} → {$downloadDir}（种子文件：{$hasTorrent}）");
                $stats['ok']++;
                continue;
            }

            $this->log("{$infohash}：{$downloadDirOriginal} → {$downloadDir}，推送种子到目标下载器 ...");
            try {
                $ret = $toClient->addTorrent($torrent);
            } catch (Throwable $throwable) {
                $this->log("【推送种子】异常：{$throwable->getMessage()}");
                $ret = false;
            }

            if ($ret) {
                $stats['ok']++;
                $state = 1;
                $this->sendAfter($toClient, $infohash, $ret);
                // 转移成功时删除源做种（不删资源）
                if ($task->deleteTorrent) {
                    $fromClient->delete($this->lastDeleteId);
                }
            } else {
                $stats['fail']++;
                $state = 0;
            }

            $this->store->record($task->name, $fromKey, $toKey, $infohash, [
                'directory' => $downloadDirOriginal,
                'convert_directory' => $downloadDir,
                'message' => is_string($ret) ? $ret : json_encode($ret, JSON_UNESCAPED_UNICODE),
                'state' => $state,
                'last_time' => time(),
            ]);
        }

        $this->log(sprintf(
            '任务 %s 完成：成功 %d，跳过 %d，失败 %d',
            $task->name,
            $stats['ok'],
            $stats['skip'],
            $stats['fail']
        ));
    }

    /** 待删除的源种子标识（qB=hash，Tr=id），读取元信息时写入 */
    private string|int $lastDeleteId = '';

    /**
     * 读取种子元数据：Transmission 源
     * @param array<string, array<string, mixed>> $move
     */
    private function handleTransmission(string $infohash, string $path, array $move): Torrent
    {
        $extraOptions = ['paused' => $this->task->paused];

        // 优先使用 API 提供的种子路径
        $torrentFile = (string)($move[$infohash]['torrentFile'] ?? '');
        $this->lastDeleteId = (int)($move[$infohash]['id'] ?? 0);
        // API 提供的种子路径不存在时，使用配置内指定的 BT_backup 路径
        if ('' === $torrentFile || !is_file($torrentFile)) {
            $torrentFile = str_replace('\\', '/', $torrentFile);
            $torrentFile = $path . strrchr($torrentFile, '/');
        }
        // 再次检查
        if (!is_file($torrentFile)) {
            throw new \InvalidArgumentException(
                "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，种子文件 `{$torrentFile}` 不存在，无法完成转移！"
                    . '（来源为 Transmission 时需在 from.torrent_path 配置 BT_backup 目录并挂载进容器）'
            );
        }

        $metadata = (string)file_get_contents($torrentFile);
        return new Torrent($metadata, true, '', $extraOptions);
    }

    /**
     * 读取种子元数据：qBittorrent 源
     * @param array<string, array<string, mixed>> $move
     */
    private function handleQBittorrent(string $infohash, string $path, array $move, bool $needPatchTorrent, bool $paused): Torrent
    {
        $extraOptions = [
            'autoTMM' => 'false',   // 关闭自动种子管理
            'root_folder' => !empty($this->task->to['root_folder']) ? 'true' : 'false',
        ];
        if ($paused) {
            $extraOptions['paused'] = 'true';
        }
        if ($this->task->skipCheck) {
            $extraOptions['skip_checking'] = 'true';   // 转移成功，跳校验
        }

        if ('' === $path) {
            throw new \InvalidArgumentException(
                "{$this->task->from['url']} 未设置 torrent_path（qB 的 BT_backup 目录），无法完成转移！"
            );
        }

        $torrentFile = $path . DIRECTORY_SEPARATOR . $infohash . '.torrent';
        $fastResumePath = $path . DIRECTORY_SEPARATOR . $infohash . '.fastresume';
        $this->lastDeleteId = $infohash;

        if (!is_file($torrentFile)) {
            // 先检查是否为空
            $infohashV1 = (string)($move[$infohash]['infohash_v1'] ?? '');
            if ('' === $infohashV1) {
                throw new \InvalidArgumentException(
                    "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，种子文件 {$torrentFile} 不存在且 infohash_v1 为空，无法完成转移！"
                );
            }
            // 高版本 qb 下载器，v2 种子用 infohash_v1 定位
            $v1Path = $path . DIRECTORY_SEPARATOR . $infohashV1 . '.torrent';
            if (is_file($v1Path)) {
                $torrentFile = $v1Path;
            } else {
                throw new \InvalidArgumentException(
                    "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，种子文件 `{$torrentFile}` 不存在，无法完成转移！"
                );
            }
        }

        $metadata = (string)file_get_contents($torrentFile);
        $parsed = null;
        try {
            $parsed = Bencode::decode($metadata);
            if (empty($parsed['announce'])) {
                $needPatchTorrent = true;
            }
        } catch (ParseException) {
            $this->log('种子元数据解析失败：' . $infohash);
        }

        if ($needPatchTorrent) {
            $this->log("{$infohash} 未发现 tracker 信息，尝试补充 tracker 信息 ...");
            if (empty($parsed)) {
                throw new \InvalidArgumentException(
                    "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，种子文件 `{$torrentFile}` 解析失败，无法完成转移！"
                );
            }
            if (empty($parsed['announce'])) {
                if (!empty($move[$infohash]['tracker'])) {
                    $parsed['announce'] = $move[$infohash]['tracker'];
                } else {
                    if (!is_file($fastResumePath)) {
                        throw new \InvalidArgumentException(
                            "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，resume 文件 `{$fastResumePath}` 不存在，无法完成转移！"
                        );
                    }
                    try {
                        $parsedFastResume = Bencode::load($fastResumePath);
                    } catch (ParseException $e) {
                        throw new \InvalidArgumentException(
                            "{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，resume 文件 `{$fastResumePath}` 解析失败`{$e->getMessage()}`，无法完成转移！"
                        );
                    }
                    $trackers = $parsedFastResume['trackers'] ?? [];
                    if (count($trackers) > 0 && !empty($trackers[0])) {
                        if (is_array($trackers[0]) && count($trackers[0]) > 0 && !empty($trackers[0][0])) {
                            $parsed['announce'] = $trackers[0][0];
                        }
                    } else {
                        $this->log("{$this->task->from['url']} 的 `{$move[$infohash]['name']}`，resume 文件不包含 tracker 地址，无法补充！");
                    }
                }
            }
            $metadata = Bencode::encode($parsed);
        }

        return new Torrent($metadata, true, '', $extraOptions);
    }

    /**
     * 推送前：按 marker 配置打分类/标签参数
     */
    private function sendBefore(Torrent $torrent, AbstractClient $toClient): void
    {
        $marker = $this->task->marker;
        if ('none' === $marker['type'] || '' === $marker['name']) {
            return;
        }
        if ($toClient instanceof \Myuu\Client\QBittorrentClient) {
            if ('category' === $marker['type']) {
                $torrent->parameters['category'] = $marker['name'];
            }
        } else {
            // Tr 只有标签
            $torrent->parameters['labels'] = [$marker['name']];
        }
    }

    /**
     * 推送后：qB 的 tag 标记需在种子添加成功后单独调用
     */
    private function sendAfter(AbstractClient $toClient, string $infohash, mixed $result): void
    {
        try {
            if ($toClient instanceof \Myuu\Client\QBittorrentClient
                && 'tag' === $this->task->marker['type']
                && '' !== $this->task->marker['name']
                && is_string($result)
                && str_contains(strtolower($result), 'ok')
            ) {
                $toClient->torrentAddTags($infohash, $this->task->marker['name']);
            }
        } catch (Throwable $throwable) {
            $this->log('打标签异常：' . $throwable->getMessage());
        }
    }

    /**
     * 检测来源 qBittorrent 版本号是否 ≥4.4.0（v2 种子种子文件改用 infohash_v1 命名）
     */
    private function versionGEQ44(AbstractClient $client): bool
    {
        if ($client instanceof \Myuu\Client\QBittorrentClient) {
            $version = $client->appVersion();
            $this->log("来源 qBittorrent 版本号：{$version}");
            return version_compare(ltrim($version, 'v'), '4.4.0', '>=');
        }
        return false;
    }

    private function log(string $message): void
    {
        echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
    }
}
