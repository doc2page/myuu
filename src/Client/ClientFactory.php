<?php

declare(strict_types=1);

namespace Myuu\Client;

/**
 * 下载器客户端工厂
 *
 * 替代 IYUU 的 ClientDownloader（ledc/container 驱动管理），
 * 两个驱动直接实例化，无需容器。
 */
final class ClientFactory
{
    public static function create(array $config): AbstractClient
    {
        return match (($config['type'] ?? '')) {
            'qbit', 'qbittorrent' => new QBittorrentClient($config),
            'transmission', 'tr' => new TransmissionClient($config),
            default => throw new \InvalidArgumentException('未匹配到下载器类型：' . ($config['type'] ?? '空')),
        };
    }
}
