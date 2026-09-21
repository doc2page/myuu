<?php

declare(strict_types=1);

namespace Myuu\Console;

use Myuu\Transfer\TaskConfig;
use Myuu\Transfer\TransferService;
use Myuu\Transfer\TransferStore;

/**
 * CLI 入口
 *
 * 用法：
 *   php bin/transfer                         常驻模式：按 interval 循环执行全部任务
 *   php bin/transfer --once                  执行一轮后退出
 *   php bin/transfer --dry-run               试运行：不推送、不写缓存
 *   php bin/transfer --task <name>           只执行指定任务
 *   php bin/transfer --reset <name>          清空指定任务的去重缓存后退出
 *   php bin/transfer --config <path>         指定配置文件
 */
final class App
{
    private const DEFAULT_CONFIG = '/app/config/config.json';
    private const DEFAULT_DATA = '/app/data/transfer.sqlite';

    /** @var array<string, mixed> */
    private array $config = [];

    public function __construct(private readonly array $argv)
    {
    }

    public function run(): int
    {
        $options = $this->parseOptions();
        $configPath = (string)($options['config'] ?? (is_file(self::DEFAULT_CONFIG) ? self::DEFAULT_CONFIG : getcwd() . '/config/config.json'));
        if (!is_file($configPath)) {
            // 首次启动：从内置示例自动生成，提示用户编辑后重启
            foreach (['/app/config.example.json', dirname(__DIR__, 2) . '/config/config.example.json', getcwd() . '/config/config.example.json'] as $example) {
                if (is_file($example) && copy($example, $configPath)) {
                    $this->log("首次启动：已生成默认配置 {$configPath}，请编辑下载器地址与路径映射后重启容器");
                    return 1;
                }
            }
            $this->log("配置文件不存在：{$configPath}，且无法定位示例配置");
            return 1;
        }

        $config = json_decode((string)file_get_contents($configPath), true);
        if (!is_array($config)) {
            $this->log('配置文件 JSON 解析失败：' . json_last_error_msg());
            return 1;
        }
        $this->config = $config;

        // 任务列表（单个任务配置错误时跳过该任务，不影响其他任务）
        $tasks = [];
        foreach ((array)($config['tasks'] ?? []) as $taskArr) {
            try {
                $task = TaskConfig::fromArray($taskArr);
            } catch (\InvalidArgumentException $e) {
                $this->log('配置错误，跳过该任务：' . $e->getMessage());
                continue;
            }
            $tasks[$task->name] = $task;
        }
        if (empty($tasks)) {
            $this->log('配置中没有可用任务（tasks 数组为空或全部无效）');
            return 1;
        }

        // 只跑指定任务
        if (null !== ($taskName = $options['task'] ?? null)) {
            if (!isset($tasks[$taskName])) {
                $this->log("任务不存在：{$taskName}（可用：" . implode('、', array_keys($tasks)) . '）');
                return 1;
            }
            $tasks = [$taskName => $tasks[$taskName]];
        }

        $dataPath = (string)($config['data_path'] ?? (is_dir(dirname(self::DEFAULT_DATA)) ? self::DEFAULT_DATA : getcwd() . '/data/transfer.sqlite'));
        $store = new TransferStore($dataPath);

        // 清缓存后退出
        if (null !== ($resetName = $options['reset'] ?? null)) {
            if (!isset($tasks[$resetName])) {
                $this->log("任务不存在：{$resetName}");
                return 1;
            }
            $n = $store->reset($resetName);
            $this->log("已清空任务 {$resetName} 的缓存记录 {$n} 条");
            return 0;
        }

        $dryRun = isset($options['dry-run']);
        $once = isset($options['once']) || $dryRun;

        // 常驻循环
        while (true) {
            $this->log('===== 开始执行转移任务' . ($dryRun ? '（DRY-RUN）' : '') . ' =====');
            foreach ($tasks as $task) {
                try {
                    (new TransferService($task, $store, $dryRun))->run();
                } catch (\Throwable $throwable) {
                    $this->log("任务 {$task->name} 异常终止：{$throwable->getMessage()}");
                }
            }
            if ($once) {
                break;
            }
            $interval = max(60, (int)($this->config['interval'] ?? 3600));
            $this->log("全部任务执行完毕，休眠 {$interval} 秒后进入下一轮");
            sleep($interval);
        }
        return 0;
    }

    /**
     * 解析命令行参数
     * @return array<string, string|true>
     */
    private function parseOptions(): array
    {
        $options = [];
        $count = count($this->argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $this->argv[$i];
            switch ($arg) {
                case '--once':
                case '--dry-run':
                    $options[substr($arg, 2)] = true;
                    break;
                case '--config':
                case '--task':
                case '--reset':
                    $options[substr($arg, 2)] = (string)($this->argv[++$i] ?? '');
                    break;
                case '--help':
                case '-h':
                    echo "用法：php bin/transfer [--once] [--dry-run] [--task <name>] [--reset <name>] [--config <path>]\n";
                    exit(0);
                default:
                    $this->log("忽略未知参数：{$arg}");
            }
        }
        return $options;
    }

    private function log(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}
