<?php

declare(strict_types=1);

namespace Myuu\Client;

use Myuu\Http\MiniCurl;

/**
 * 下载器客户端抽象基类
 *
 * 自 IYUU BittorrentClient\Clients 裁剪：持有配置与 HTTP 实例。
 * 所有网络请求均发往用户配置的下载器地址，无任何第三方外联。
 */
abstract class AbstractClient
{
    /** 种子列表在返回结构中的 key */
    public const TORRENT_LIST = 'lists';

    /**
     * @param array<string, mixed> $config 下载器配置（url/username/password 等）
     */
    public function __construct(protected readonly array $config)
    {
        $this->curl = new MiniCurl();
        $this->curl->setTimeout(60, 600)
            ->setSslVerify(false, false)
            ->setHeader('Origin', $this->getHostname())
            ->setHeader('Referer', $this->getClientUrl());
        $this->initialize();
    }

    protected MiniCurl $curl;

    /**
     * 子类初始化（登录等）
     */
    protected function initialize(): void
    {
    }

    /**
     * 下载器完整 RPC 地址（末尾不带 /）
     */
    abstract public function getClientUrl(): string;

    /**
     * 主机名（含端口）
     */
    public function getHostname(): string
    {
        $parts = parse_url($this->getClientUrl());
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? null;
        return $port ? $host . ':' . $port : $host;
    }

    /**
     * 获取做种列表
     * 返回结构：['hash' => json, 'sha1' => sha1, 'hashString' => [hash => save_path], 'lists' => [hash => torrent]]
     */
    abstract public function getTorrentList(): array;

    /**
     * 添加种子
     */
    abstract public function addTorrent(\Myuu\Contract\Torrent $torrent): string|bool|null;

    /**
     * 删除种子（不删数据）
     * @param string|int $id qB=hash，Tr=torrent id
     */
    abstract public function delete(string|int $id, bool $deleteFiles = false): string|bool|null;

    /**
     * 客户端版本号
     */
    abstract public function appVersion(): string;

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    protected function newCurl(): MiniCurl
    {
        $curl = new MiniCurl();
        $curl->setTimeout(60, 600)
            ->setSslVerify(false, false)
            ->setHeader('Origin', $this->getHostname())
            ->setHeader('Referer', $this->getClientUrl());
        return $curl;
    }
}
