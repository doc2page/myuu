<?php

declare(strict_types=1);

namespace Myuu\Contract;

/**
 * 种子传输对象
 * payload 为种子元数据（.torrent 文件原始内容）或下载 URL
 */
final class Torrent
{
    /**
     * @param string $payload 种子元数据或URL
     * @param bool $isMetadata true=元数据 false=URL
     * @param string $savePath 保存路径
     * @param array<string, mixed> $parameters 附加参数
     */
    public function __construct(
        public readonly string $payload,
        public readonly bool $isMetadata = true,
        public string $savePath = '',
        public array $parameters = []
    ) {
    }

    /**
     * 是否为种子元数据
     */
    public function isMetadata(): bool
    {
        return $this->isMetadata;
    }
}
