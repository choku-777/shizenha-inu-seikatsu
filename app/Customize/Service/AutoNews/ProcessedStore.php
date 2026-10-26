<?php

namespace Customize\Service\AutoNews;

/**
 * 処理した原稿をサーバー側に記録する（二重作成を防ぐ・途中で止まった作業を続ける）。
 *
 * お知らせを作る「前」に status=pending を書き、作った「後」に結果を書く。
 * pending のまま残っていたら「作られたか分からない」ので、自動では作り直さず人に確認してもらう
 * （作り直すと二重公開になりうるため、取りこぼし側に倒す）。
 */
class ProcessedStore
{
    private string $dir;

    private array $data = ['posts' => []];

    /** @var resource|null */
    private $lock;

    public function __construct()
    {
        // app/Customize/Service/AutoNews → プロジェクト直下の var/auto_news（デプロイで消えない場所）
        $this->dir = dirname(__DIR__, 4).'/var/auto_news';
    }

    /**
     * 同時に2つ動かないようにする。取れなければ false。
     */
    public function acquire(): bool
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('記録用のフォルダを作れません');
        }
        $this->lock = fopen($this->dir.'/lock', 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $file = $this->dir.'/state.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['posts']) || !is_array($data['posts'])) {
                throw new \RuntimeException('処理済みの記録が壊れています（var/auto_news/state.json）');
            }
            $this->data = $data;
        }

        return true;
    }

    public function release(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function get(string $key): ?array
    {
        return $this->data['posts'][$key] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->data['posts'];
    }

    /**
     * 記録を追加・更新して、すぐにファイルへ保存する（保存できなければ例外で止める）。
     */
    public function put(string $key, array $values): array
    {
        $record = array_merge($this->data['posts'][$key] ?? ['created_at' => date('Y-m-d H:i:s')], $values, ['updated_at' => date('Y-m-d H:i:s')]);
        $this->data['posts'][$key] = $record;

        $tmp = $this->dir.'/state.json.tmp';
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, $this->dir.'/state.json')) {
            throw new \RuntimeException('処理済みの記録を保存できません');
        }

        return $record;
    }

    /**
     * 今日、お知らせを作った（または作りかけた）件数。
     */
    public function createdToday(): int
    {
        $today = date('Y-m-d');
        $count = 0;
        foreach ($this->data['posts'] as $post) {
            if (in_array($post['status'] ?? '', ['pending', 'unknown', 'published', 'review', 'trial'], true)
                && str_starts_with((string) ($post['created_at'] ?? ''), $today)) {
                ++$count;
            }
        }

        return $count;
    }
}
