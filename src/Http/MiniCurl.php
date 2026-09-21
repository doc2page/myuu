<?php

declare(strict_types=1);

namespace Myuu\Http;

/**
 * 极简 HTTP 客户端（原生 curl 封装）
 *
 * 替代 IYUU 的 Ledc\Curl\Curl，仅实现下载器交互所需接口。
 * 不含任何重试、代理、上报逻辑。
 */
final class MiniCurl
{
    /** @var array<string, string> 请求头 */
    private array $headers = [];

    /** @var array<int, string> 响应头原始行 */
    private array $responseHeaders = [];

    /** 连接超时（秒） */
    private int $connectTimeout = 60;

    /** 总超时（秒） */
    private int $timeout = 600;

    /** 响应体 */
    public string|null $response = null;

    /** 是否发生错误 */
    public bool $error = false;

    /** 错误消息 */
    public string|null $errorMessage = null;

    /** HTTP 状态码 */
    private int $httpStatus = 0;

    public function __construct()
    {
        $this->headers = [
            'User-Agent' => 'myuu/1.0',
        ];
    }

    /**
     * 设置请求头
     */
    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 设置 Cookie（qB 的 SID）
     */
    public function setCookies(string $cookie): static
    {
        $this->headers['Cookie'] = $cookie;
        return $this;
    }

    /**
     * HTTP Basic 认证（Tr）
     */
    public function setBasicAuthentication(string $username, string $password): static
    {
        $this->setHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
        return $this;
    }

    /**
     * X-Requested-With（Tr RPC 需要）
     */
    public function setXRequestedWith(): static
    {
        return $this->setHeader('X-Requested-With', 'XMLHttpRequest');
    }

    /**
     * 设置超时：连接 / 总时长
     */
    public function setTimeout(int $connect, int $wait): static
    {
        $this->connectTimeout = $connect;
        $this->timeout = $wait;
        return $this;
    }

    /**
     * 是否校验 SSL 证书
     */
    public function setSslVerify(bool $verifyPeer, bool $verifyHost): static
    {
        $this->sslVerifyPeer = $verifyPeer;
        $this->sslVerifyHost = $verifyHost;
        return $this;
    }

    private bool $sslVerifyPeer = false;
    private bool $sslVerifyHost = false;

    /**
     * GET 请求
     * @param array<string, mixed> $query
     */
    public function get(string $url, array $query = []): static
    {
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->execute('GET', $url, null);
    }

    /**
     * POST application/x-www-form-urlencoded
     * @param array<string, mixed> $data
     */
    public function postForm(string $url, array $data): static
    {
        return $this->execute('POST', $url, http_build_query($data), 'application/x-www-form-urlencoded');
    }

    /**
     * POST 原始请求体（qB torrent_add 手拼 multipart 用）
     */
    public function postRaw(string $url, string $body, string $contentType): static
    {
        return $this->execute('POST', $url, $body, $contentType);
    }

    /**
     * POST JSON（Tr RPC）
     * @param array<string, mixed> $data
     */
    public function postJson(string $url, array $data): static
    {
        return $this->execute('POST', $url, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 'application/json');
    }

    /**
     * 是否成功（2xx）
     */
    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * HTTP 状态码
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * 全部响应头原始行
     * @return array<int, string>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * 取指定响应头（不区分大小写）
     */
    public function getResponseHeader(string $name): string|null
    {
        $needle = strtolower($name) . ':';
        foreach ($this->responseHeaders as $line) {
            if (str_starts_with(strtolower($line), $needle)) {
                return trim(substr($line, strlen($needle)));
            }
        }
        return null;
    }

    /**
     * 重置请求头（保留 User-Agent），用于下一次独立请求
     */
    public function reset(): static
    {
        $ua = $this->headers['User-Agent'] ?? 'myuu/1.0';
        $this->headers = ['User-Agent' => $ua];
        $this->responseHeaders = [];
        $this->response = null;
        $this->error = false;
        $this->errorMessage = null;
        $this->httpStatus = 0;
        return $this;
    }

    /**
     * 执行请求
     */
    private function execute(string $method, string $url, string|null $body, string|null $contentType = null): static
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if (null !== $contentType && !isset($this->headers['Content-Type'])) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerifyHost ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $trimmed = trim($line);
                if ('' !== $trimmed) {
                    $this->responseHeaders[] = $trimmed;
                }
                return strlen($line);
            },
        ]);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $this->error = true;
            $this->errorMessage = curl_error($ch) ?: 'unknown curl error';
            $this->httpStatus = 0;
            $this->response = null;
        } else {
            $this->error = false;
            $this->errorMessage = null;
            $this->httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $this->response = is_string($response) ? $response : '';
        }
        curl_close($ch);
        return $this;
    }
}
