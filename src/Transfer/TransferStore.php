<?php

declare(strict_types=1);

namespace Myuu\Transfer;

/**
 * 转移去重缓存（SQLite）
 *
 * 对应 IYUU 的 cn_transfer 表：成功与失败均记录，
 * 已存在记录即跳过（与原版一致，避免反复重试坏种）。
 */
final class TransferStore
{
    private \PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new \PDO('sqlite:' . $sqlitePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS transfer (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task TEXT NOT NULL,
    from_client TEXT NOT NULL,
    to_client TEXT NOT NULL,
    info_hash TEXT NOT NULL,
    directory TEXT DEFAULT '',
    convert_directory TEXT DEFAULT '',
    torrent_file TEXT DEFAULT '',
    message TEXT DEFAULT '',
    state INTEGER DEFAULT 0,
    last_time INTEGER DEFAULT 0,
    UNIQUE(task, from_client, to_client, info_hash)
)
SQL);
    }

    /**
     * 是否已存在缓存记录
     */
    public function exists(string $task, string $from, string $to, string $infoHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM transfer WHERE task = ? AND from_client = ? AND to_client = ? AND info_hash = ?'
        );
        $stmt->execute([$task, $from, $to, $infoHash]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * 记录转移结果（存在则更新）
     */
    public function record(string $task, string $from, string $to, string $infoHash, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transfer (task, from_client, to_client, info_hash, directory, convert_directory, torrent_file, message, state, last_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(task, from_client, to_client, info_hash) DO UPDATE SET
                directory = excluded.directory,
                convert_directory = excluded.convert_directory,
                torrent_file = excluded.torrent_file,
                message = excluded.message,
                state = excluded.state,
                last_time = excluded.last_time'
        );
        $stmt->execute([
            $task,
            $from,
            $to,
            $infoHash,
            (string)($fields['directory'] ?? ''),
            (string)($fields['convert_directory'] ?? ''),
            (string)($fields['torrent_file'] ?? ''),
            (string)($fields['message'] ?? ''),
            (int)($fields['state'] ?? 0),
            (int)($fields['last_time'] ?? time()),
        ]);
    }

    /**
     * 清空指定任务的缓存（重跑用）
     */
    public function reset(string $task): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM transfer WHERE task = ?');
        $stmt->execute([$task]);
        return (int)$this->pdo->query('SELECT changes()')->fetchColumn();
    }
}
