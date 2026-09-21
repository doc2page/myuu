<?php

declare(strict_types=1);

namespace Myuu\Transfer;

use InvalidArgumentException;

/**
 * 单个转移任务的配置（自 JSON 解析并校验）
 *
 * 字段对应 IYUU 计划任务参数，语义保持一致：
 * - path_filter / path_selector：前缀匹配
 * - path_convert_type：eq | add | sub | replace
 * - marker：转移到目标下载器后的分类/标签（none=不打标）
 */
final class TaskConfig
{
    /**
     * @param array<string, mixed> $from 来源下载器配置
     * @param array<string, mixed> $to 目标下载器配置
     * @param array<int, string> $pathFilter
     * @param array<int, string> $pathSelector
     * @param array<string, string> $convertRule
     * @param array{type: string, name: string} $marker
     */
    private function __construct(
        public readonly string $name,
        public readonly array $from,
        public readonly array $to,
        public readonly array $pathFilter,
        public readonly array $pathSelector,
        public readonly string $convertType,
        public readonly array $convertRule,
        public readonly bool $skipCheck,
        public readonly bool $paused,
        public readonly bool $deleteTorrent,
        public readonly array $marker
    ) {
    }

    /**
     * @param array<string, mixed> $task
     */
    public static function fromArray(array $task): self
    {
        $name = trim((string)($task['name'] ?? ''));
        if ('' === $name) {
            throw new InvalidArgumentException('任务缺少 name');
        }
        foreach (['from', 'to'] as $side) {
            if (empty($task[$side]['url']) || empty($task[$side]['type'])) {
                throw new InvalidArgumentException("任务 {$name} 的 {$side} 缺少 type 或 url");
            }
        }

        $convertType = (string)($task['path_convert_type'] ?? 'eq');
        if (!in_array($convertType, ['eq', 'add', 'sub', 'replace'], true)) {
            throw new InvalidArgumentException("任务 {$name} 的 path_convert_type 非法：{$convertType}");
        }

        // 转换规则：[{from, to}] → [前缀 => 值]
        $convertRule = [];
        foreach ((array)($task['path_convert_rule'] ?? []) as $rule) {
            $from = trim((string)($rule['from'] ?? ''));
            if ('' !== $from) {
                $convertRule[$from] = (string)($rule['to'] ?? '');
            }
        }
        if (empty($convertRule) && 'eq' !== $convertType) {
            $convertType = 'eq';
        }

        $markerType = (string)($task['marker']['type'] ?? 'none');
        if (!in_array($markerType, ['none', 'category', 'tag', 'labels'], true)) {
            $markerType = 'none';
        }

        return new self(
            name: $name,
            from: $task['from'],
            to: $task['to'],
            pathFilter: array_values(array_map('strval', (array)($task['path_filter'] ?? []))),
            pathSelector: array_values(array_map('strval', (array)($task['path_selector'] ?? []))),
            convertType: $convertType,
            convertRule: $convertRule,
            skipCheck: (bool)($task['skip_check'] ?? false),
            paused: (bool)($task['paused'] ?? false),
            deleteTorrent: (bool)($task['delete_torrent'] ?? false),
            marker: ['type' => $markerType, 'name' => (string)($task['marker']['name'] ?? '')],
        );
    }

    /**
     * 路径规则
     */
    public function pathRule(): PathRule
    {
        return new PathRule($this->pathFilter, $this->pathSelector, $this->convertType, $this->convertRule);
    }

    /**
     * 下载器标识（用于去重缓存）
     */
    public function clientKey(array $client): string
    {
        return rtrim((string)$client['url'], '/');
    }
}
