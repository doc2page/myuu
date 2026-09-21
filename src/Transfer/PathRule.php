<?php

declare(strict_types=1);

namespace Myuu\Transfer;

/**
 * 路径规则：过滤器 / 选择器 / 转换
 *
 * 自 IYUU TransferServices 裁剪为纯函数类。
 * 原理：前缀匹配；rtrim("/\\") 提高跨平台（Windows）转移兼容性。
 */
final class PathRule
{
    /**
     * @param array<int, string> $pathFilter 过滤器（优先级高：命中即跳过）
     * @param array<int, string> $pathSelector 选择器（优先级低：命中才转移）
     * @param string $convertType eq|add|sub|replace
     * @param array<string, string> $convertRule 转换规则：前缀 => 值
     */
    public function __construct(
        private readonly array $pathFilter = [],
        private readonly array $pathSelector = [],
        private readonly string $convertType = 'eq',
        private readonly array $convertRule = []
    ) {
    }

    /**
     * 过滤器/选择器判定
     * @return bool true=跳过该种子 false=继续处理
     */
    public function filter(string $path): bool
    {
        $path = rtrim($path, "/\\");
        $filter = $this->pathFilter;
        $selector = $this->pathSelector;
        if (empty($filter) && empty($selector)) {
            return false;
        }

        switch (true) {
            case empty($filter):
                // 仅设置选择器：命中才转移
                foreach ($selector as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }
                return true;
            case empty($selector):
                // 仅设置过滤器：命中即跳过
                foreach ($filter as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return true;
                    }
                }
                return false;
            default:
                // 先过滤器（命中即跳过），后选择器（命中才转移）
                foreach ($filter as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return true;
                    }
                }
                foreach ($selector as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }
                return true;
        }
    }

    /**
     * 实际路径与相对路径之间互相转换
     * @return string|null null=转换失败（路径转换参数配置错误）
     */
    public function convert(string $path): ?string
    {
        if ('eq' === $this->convertType) {
            return $path;
        }

        $path = rtrim($path, "/\\");
        foreach ($this->convertRule as $prefix => $val) {
            if (str_starts_with($path, $prefix)) {
                return match ($this->convertType) {
                    'add' => $val . $path,
                    'sub' => substr($path, strlen($prefix)),
                    'replace' => $val . substr($path, strlen($prefix)),
                    default => $path,
                };
            }
        }
        return null;
    }
}
