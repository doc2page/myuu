<?php

declare(strict_types=1);

namespace Myuu\Client;

use Myuu\Contract\Torrent;
use Myuu\Exception\NotFoundException;
use Myuu\Exception\ServerErrorException;
use Myuu\Exception\UnauthorizedException;
use Myuu\Http\MiniCurl;
use RuntimeException;

/**
 * Transmission RPC 客户端
 *
 * 自 Iyuu\BittorrentClient\Driver\transmission\Client 裁剪，
 * 仅保留转移做种所需的 RPC 方法；所有请求仅发往配置的下载器地址。
 */
final class TransmissionClient extends AbstractClient
{
    /** CSRF 会话头 */
    private string $sessionId = '';

    /** 客户端 RPC 地址（末尾不带 /） */
    private string $clientUrl = '';

    protected function initialize(): void
    {
        $this->clientUrl = rtrim((string)$this->config('url', ''), '/');
        if ('' === $this->clientUrl) {
            throw new RuntimeException('Transmission 配置缺少 url');
        }
        $this->login();
    }

    public function getClientUrl(): string
    {
        return $this->clientUrl;
    }

    /**
     * 添加种子
     */
    public function addTorrent(Torrent $torrent): string|bool|null
    {
        $extra = $torrent->parameters;
        if ('' !== $torrent->savePath) {
            $extra['download-dir'] = $torrent->savePath;
        }
        if ($torrent->isMetadata()) {
            $extra['metainfo'] = base64_encode($torrent->payload);
        } else {
            $extra['filename'] = $torrent->payload;
        }
        return $this->request('torrent-add', $extra);
    }

    /**
     * 删除种子
     * @param int|array<int, int>|string $ids
     */
    public function delete(string|int $ids, bool $deleteFiles = false): string|bool|null
    {
        return $this->request('torrent-remove', [
            'ids' => is_array($ids) ? $ids : [$ids],
            'delete-local-data' => $deleteFiles,
        ]);
    }

    public function appVersion(): string
    {
        $response = $this->request('session-get');
        $resp = json_decode((string)$response, true);
        return (string)($resp['arguments']['version'] ?? '');
    }

    /**
     * 获取做种列表（仅 status=6 Seeding）
     */
    public function getTorrentList(): array
    {
        $fields = ['id', 'status', 'name', 'hashString', 'downloadDir', 'torrentFile'];
        $res = $this->request('torrent-get', ['fields' => $fields, 'ids' => []]);
        $res = $res ? json_decode((string)$res, true) : [];
        if (!isset($res['result']) || 'success' !== $res['result']) {
            throw new NotFoundException('下载器无响应');
        }
        if (empty($res['arguments']['torrents'])) {
            throw new NotFoundException('下载器种子数据为空');
        }
        $torrents = $res['arguments']['torrents'];
        // 过滤，只保留正常做种（status 6 = Seeding）
        $torrents = array_values(array_filter($torrents, static fn(array $v): bool => isset($v['status']) && $v['status'] === 6));
        if (empty($torrents)) {
            throw new NotFoundException('从下载器未获取到做种数据');
        }

        $infoHash = array_column($torrents, 'hashString');
        sort($infoHash);
        $json = json_encode($infoHash, JSON_UNESCAPED_UNICODE);
        return [
            'hash' => $json,
            'sha1' => sha1((string)$json),
            // hashString 键名、目录为键值
            'hashString' => array_column($torrents, 'downloadDir', 'hashString'),
            // 转移做种使用（含 id/torrentFile）
            static::TORRENT_LIST => array_column($torrents, null, 'hashString'),
        ];
    }

    /**
     * 登录：获取 X-Transmission-Session-Id（409 修复机制）
     */
    public function login(): string
    {
        $curl = $this->newCurl();
        $curl->setBasicAuthentication((string)$this->config('username', ''), (string)$this->config('password', ''));
        $curl->get($this->clientUrl);
        if ($curl->isSuccess() && $curl->response) {
            if (preg_match('#<code>X-Transmission-Session-Id: (.*?)</code>#i', $curl->response, $matches)) {
                $this->sessionId = $matches[1] ?? '';
                return $this->sessionId;
            }
        }

        // 409 Conflict：响应头携带会话 ID
        if (409 === $curl->getHttpStatus()) {
            if ($sid = $curl->getResponseHeader('X-Transmission-Session-Id')) {
                $this->sessionId = $sid;
                return $this->sessionId;
            }
        }

        throw new ServerErrorException('下载器登录失败：' . ($curl->errorMessage ?? 'unknown'));
    }

    /**
     * RPC 统一请求
     * @param array<string, mixed> $arguments
     */
    protected function request(string $method, array $arguments = []): string
    {
        $arguments = $this->cleanRequestData($arguments);
        $data = [
            'method' => $method,
            'arguments' => $arguments,
        ];

        $retry = 1;
        do {
            if ('' === $this->sessionId && !$this->login()) {
                throw new UnauthorizedException('无法获得 X-Transmission-Session-Id');
            }
            $curl = $this->newCurl();
            $curl->setBasicAuthentication((string)$this->config('username', ''), (string)$this->config('password', ''));
            $curl->setXRequestedWith();
            $curl->setHeader('Referer', $this->getHostname() . '/transmission/web/');
            $curl->setHeader('X-Transmission-Session-Id', $this->sessionId);
            $curl->postJson($this->clientUrl, $data);
            if (409 === $curl->getHttpStatus()) {
                if ($sid = $curl->getResponseHeader('X-Transmission-Session-Id')) {
                    $this->sessionId = $sid;
                } else {
                    $retry = 0;
                }
            } else {
                $retry = 0;
            }
        } while (0 < $retry--);

        if ($curl->isSuccess()) {
            return $curl->response ?? '';
        }
        throw new ServerErrorException('下载器错误：' . ($curl->errorMessage ?? 'unknown'));
    }

    /**
     * 预处理请求报文
     * 踩坑保留：locale 错误时 12.34 会编码为 12,34 导致非法 JSON；布尔转 0/1；移除空成员
     * @param array<string, mixed> $array
     * @return array<string, mixed>|null
     */
    protected function cleanRequestData(array $array): array|null
    {
        if (0 === count($array)) {
            return null;
        }
        setlocale(LC_NUMERIC, 'en_US.utf8');
        foreach ($array as $index => $value) {
            if (is_array($value)) {
                $array[$index] = $this->cleanRequestData($value);
            }
            if (empty($value) && $value !== 0 && $value !== false) {
                unset($array[$index]);
                continue;
            }
            if (is_numeric($value) && !is_bool($value)) {
                // 强制类型转换以正确编码 JSON（+0 是保持 int/float 的廉价方式）
                $array[$index] = $value + 0;
            } elseif (is_bool($value)) {
                $array[$index] = $value ? 1 : 0;
            } elseif (is_string($value)) {
                $type = mb_detect_encoding($value, 'auto');
                if ('UTF-8' !== $type) {
                    $array[$index] = mb_convert_encoding($value, 'UTF-8');
                }
            }
        }
        return $array;
    }
}
