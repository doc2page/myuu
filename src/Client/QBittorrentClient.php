<?php

declare(strict_types=1);

namespace Myuu\Client;

use Myuu\Contract\Torrent;
use Myuu\Exception\NotFoundException;
use Myuu\Exception\ServerErrorException;
use RuntimeException;

/**
 * qBittorrent Web API v2 客户端
 *
 * 自 IYuu\BittorrentClient\Driver\qBittorrent\Client 裁剪，
 * 仅保留转移做种所需的端点；所有请求仅发往配置的下载器地址。
 */
final class QBittorrentClient extends AbstractClient
{
    /** 做种状态集合 */
    private const SEEDING_STATES = ['uploading', 'stalledUP', 'pausedUP', 'queuedUP', 'checkingUP', 'forcedUP'];

    /**
     * API 接入点
     */
    private const ENDPOINTS = [
        'login' => '/api/v2/auth/login',
        'app_version' => '/api/v2/app/version',
        'torrent_list' => '/api/v2/torrents/info',
        'torrent_add' => '/api/v2/torrents/add',
        'torrent_delete' => '/api/v2/torrents/delete',
        'torrent_addTags' => '/api/v2/torrents/addTags',
    ];

    /** CSRF 使用的 Session Cookie */
    private string $sessionId = '';

    /** multipart 分隔符 */
    private string $delimiter = '';

    /** 客户端地址（末尾不带 /） */
    private string $clientUrl = '';

    protected function initialize(): void
    {
        $this->clientUrl = rtrim((string)$this->config('url', ''), '/');
        if ('' === $this->clientUrl) {
            throw new RuntimeException('qBittorrent 配置缺少 url');
        }
        if (!$this->login()) {
            throw new RuntimeException('qBittorrent 登录失败：Unable to authenticate with Web API.');
        }
    }

    public function getClientUrl(): string
    {
        return $this->clientUrl;
    }

    /**
     * 登录：获取 SID Cookie
     * 兼容 qBittorrent v4.1.5（小钢炮等），追加 QB_ 前缀 cookie
     */
    public function login(): bool
    {
        $curl = $this->newCurl();
        $curl->postForm($this->clientUrl . self::ENDPOINTS['login'], [
            'username' => (string)$this->config('username', ''),
            'password' => (string)$this->config('password', ''),
        ]);
        foreach ($curl->getResponseHeaders() as $header) {
            if (preg_match('/SID=(\S[^;]+)/', $header, $matches)) {
                // 兼容 qBittorrent v4.1.5[小钢炮等]
                $this->sessionId = $matches[0] . '; QB_' . $matches[0];
                return true;
            }
        }
        if ($curl->error) {
            throw new \RuntimeException('qBittorrent 连接失败：' . $curl->errorMessage);
        }
        return false;
    }

    public function appVersion(): string
    {
        return (string)$this->request('GET', 'app_version');
    }

    /**
     * 添加种子（元数据方式）
     */
    public function addTorrent(Torrent $torrent): string|bool|null
    {
        $parameters = $torrent->parameters;
        if ($torrent->isMetadata()) {
            $parameters['name'] = $parameters['name'] ?? 'torrents';
            $parameters['filename'] = $parameters['filename'] ?? time() . '.torrent';
            return $this->addTorrentByMetadata($torrent->payload, $torrent->savePath, $parameters);
        }
        // URL 方式（保留接口兼容，转移流程不使用）
        $parameters['urls'] = $torrent->payload;
        return $this->addTorrentByUrl($torrent->payload, $torrent->savePath, $parameters);
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    public function addTorrentByMetadata(string $metadata, string $savePath, array $extraOptions = []): string|bool|null
    {
        if ('' !== $savePath) {
            $extraOptions['savepath'] = $savePath;
        }
        $extraOptions['torrents'] = $metadata;
        // 关键：上传文件流 multipart/form-data【严格按照 api 文档编写】
        $body = $this->buildTorrent($extraOptions);
        return $this->request(
            'POST_RAW',
            'torrent_add',
            $body,
            'multipart/form-data; boundary=' . $this->delimiter
        );
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    public function addTorrentByUrl(string $url, string $savePath, array $extraOptions = []): string|bool|null
    {
        if ('' !== $savePath) {
            $extraOptions['savepath'] = $savePath;
        }
        $extraOptions['urls'] = $url;
        $body = $this->buildTorrent($extraOptions);
        return $this->request(
            'POST_RAW',
            'torrent_add',
            $body,
            'multipart/form-data; boundary=' . $this->delimiter
        );
    }

    /**
     * 删除种子
     */
    public function delete(string|int $id, bool $deleteFiles = false): string|bool|null
    {
        return $this->request('POST_FORM', 'torrent_delete', [
            'hashes' => (string)$id,
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ]);
    }

    /**
     * 给种子打标签
     * @param string|array<int, string> $tags
     */
    public function torrentAddTags(string $hash, string|array $tags): string|bool|null
    {
        return $this->request('POST_FORM', 'torrent_addTags', [
            'hashes' => $hash,
            'tags' => is_string($tags) ? $tags : implode(',', $tags),
        ]);
    }

    /**
     * 获取全部种子列表（原始）
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function getList(array $data = []): array
    {
        $result = $this->request('GET', 'torrent_list', $data);
        $res = json_decode((string)$result, true);
        if (empty($res)) {
            throw new NotFoundException('从下载器获取种子列表失败，可能 qBittorrent 暂时无响应');
        }
        return $res;
    }

    /**
     * 获取做种列表（仅正常做种状态）
     */
    public function getTorrentList(): array
    {
        $res = $this->getList();
        // 过滤，只保留正常做种
        $res = array_values(array_filter($res, static function (array $v): bool {
            return isset($v['state']) && in_array($v['state'], self::SEEDING_STATES, true);
        }));

        if (empty($res)) {
            throw new NotFoundException('从下载器未获取到做种数据');
        }

        return $this->assemble($res, 'hash', 'save_path');
    }

    /**
     * 组装返回数据（与 IYUU 结构一致，供转移流程使用）
     * @param array<int, array<string, mixed>> $res
     * @return array<string, mixed>
     */
    private function assemble(array $res, string $hashKey, string $pathKey): array
    {
        $infoHash = array_column($res, $hashKey);
        sort($infoHash);
        $json = json_encode($infoHash, JSON_UNESCAPED_UNICODE);
        return [
            'hash' => $json,
            'sha1' => sha1((string)$json),
            // hashString 键名、目录为键值
            'hashString' => array_column($res, $pathKey, $hashKey),
            // 转移做种使用
            static::TORRENT_LIST => array_column($res, null, $hashKey),
        ];
    }

    /**
     * 拼接种子上传文件流 multipart/form-data
     * @link https://github.com/qbittorrent/qBittorrent/wiki/Web-API-Documentation#add-new-torrent
     * @param array<string, mixed> $param
     */
    private function buildTorrent(array $param): string
    {
        $this->delimiter = str_replace('.', '', uniqid('--------------------', true));
        $eol = "\r\n";
        $data = '';
        // 拼接文件流
        $data .= '--' . $this->delimiter . $eol;
        $data .= 'Content-Disposition: form-data; name="' . $param['name'] . '"; filename="' . $param['filename'] . '"' . $eol;
        $data .= 'Content-Type: application/x-bittorrent' . $eol . $eol;
        $data .= $param['torrents'] . $eol;
        unset($param['name'], $param['filename'], $param['torrents']);
        foreach ($param as $name => $content) {
            $data .= '--' . $this->delimiter . $eol;
            $data .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $data .= $content . $eol;
        }
        $data .= '--' . $this->delimiter . '--' . $eol;
        return $data;
    }

    /**
     * 统一请求入口
     * @param array<string, mixed> $data
     */
    private function request(string $method, string $endpoint, array|string $data = [], string $contentType = ''): string|bool|null
    {
        $curl = $this->newCurl();
        $curl->setCookies($this->sessionId);
        $url = $this->clientUrl . self::ENDPOINTS[$endpoint];
        $curl = match ($method) {
            'GET' => $curl->get($url, is_array($data) ? $data : []),
            'POST_FORM' => $curl->postForm($url, is_array($data) ? $data : []),
            'POST_RAW' => $curl->postRaw($url, (string)$data, $contentType),
        };

        if ($curl->error) {
            throw new ServerErrorException('qBittorrent：' . $curl->errorMessage);
        }
        return $curl->response;
    }
}
