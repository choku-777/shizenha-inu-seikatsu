<?php

namespace Customize\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * アンバサダーのSNS投稿（掲載できるもの）をアフィリエイト管理アプリから受け取る。
 *
 * - 取得はサーバー側から X-Api-Key 付きで行う（APIキーをブラウザに出さない）
 * - 待ち時間は最大3秒。結果は1時間保存し、毎回は取りに行かない
 * - 取得に失敗したら前回の一覧で表示を続ける。それも無ければ空（＝欄ごと表示しない）
 * - 受け取った値は形を検証してから使う（URL・ID は決まった形のものだけ）
 * 接続設定はアフィリエイト計測プラグインと同じ .env（AFFILIATE_API_URL / AFFILIATE_API_KEY / AFFILIATE_SITE_CODE）。
 */
class AmbassadorPostProvider
{
    private const CACHE_KEY = 'customize.ambassador_posts.v2';

    private const LAST_GOOD_KEY = 'customize.ambassador_posts.last_good.v2';

    private const TTL = 3600;

    /** 取得に失敗したときは、この間だけ取りに行かない（相手が落ちているときに毎回待たせない） */
    private const RETRY_AFTER = 300;

    private const TIMEOUT = 3;

    private const MAX_BYTES = 1024 * 1024;

    private ?array $posts = null;

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /**
     * 表示用の投稿一覧。$productId を渡すとその商品の投稿だけ。
     */
    public function list(?int $productId = null, int $limit = 6): array
    {
        $posts = $this->all();
        if ($productId !== null) {
            $posts = array_values(array_filter($posts, fn (array $p) => $p['product_id'] === $productId));
        }

        return array_slice($posts, 0, max(0, $limit));
    }

    private function all(): array
    {
        if ($this->posts !== null) {
            return $this->posts;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            return $this->posts = (array) $item->get();
        }

        $fetched = $this->fetch();
        if ($fetched === null) {
            $last = $this->cache->getItem(self::LAST_GOOD_KEY);
            $posts = $last->isHit() ? (array) $last->get() : [];
            $item->set($posts)->expiresAfter(self::RETRY_AFTER);
        } else {
            $posts = $fetched;
            $item->set($posts)->expiresAfter(self::TTL);
            $this->cache->save($this->cache->getItem(self::LAST_GOOD_KEY)->set($posts));
        }
        $this->cache->save($item);

        return $this->posts = $posts;
    }

    /**
     * 取得して検証済みの一覧を返す。失敗は null。
     */
    private function fetch(): ?array
    {
        $base = self::env('AFFILIATE_API_URL');
        $key = self::env('AFFILIATE_API_KEY');
        $site = self::env('AFFILIATE_SITE_CODE') ?? 'umashippo';
        if ($base === null || $key === null || !preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', rtrim($base, '/'))) {
            return null;
        }
        $base = rtrim($base, '/');

        $ch = curl_init($base.'/api/sns-posts?site='.rawurlencode($site));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['X-Api-Key: '.$key, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200 || strlen((string) $body) > self::MAX_BYTES) {
            log_warning('[AmbassadorPosts] 一覧を取得できませんでした', ['status' => $status, 'error' => $error]);

            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['posts']) || !is_array($json['posts'])) {
            log_warning('[AmbassadorPosts] 一覧の形式が想定と違います');

            return null;
        }

        $posts = [];
        foreach ($json['posts'] as $raw) {
            if (is_array($raw) && ($post = $this->normalize($raw, $base)) !== null) {
                $posts[] = $post;
            }
        }

        // 動画（Instagram のリール・TikTok）を前、写真の投稿を後ろへ。
        // 同じグループの中は管理画面の表示順のまま（usort は PHP 8 以降で安定ソート）
        usort($posts, fn (array $a, array $b) => (int) $b['is_video'] <=> (int) $a['is_video']);

        return $posts;
    }

    /**
     * 1件を検証し、表示に使う形に整える。形が想定外なら null（その投稿は出さない）。
     */
    private function normalize(array $raw, string $base): ?array
    {
        $platform = $raw['platform'] ?? null;
        $url = is_string($raw['url'] ?? null) ? $raw['url'] : '';
        $postId = is_string($raw['post_id'] ?? null) ? $raw['post_id'] : '';
        $thumb = is_string($raw['thumbnail_path'] ?? null) ? $raw['thumbnail_path'] : '';

        if (!preg_match('#^/media/sns-thumbs/\d+(\?v=\d*)?$#', $thumb)) {
            return null;
        }

        if ($platform === 'instagram' && preg_match('#^https://www\.instagram\.com/(p|reel)/[A-Za-z0-9_-]+/$#', $url, $m)) {
            $embed = $url.'embed/captioned/';
            $label = 'Instagram';
            $isVideo = $m[1] === 'reel'; // /reel/ は動画。/p/ は写真または複数枚の投稿として扱う
        } elseif ($platform === 'tiktok' && preg_match('#^https://www\.tiktok\.com/@[A-Za-z0-9_.]+/video/\d+$#', $url) && preg_match('/^\d{1,30}$/', $postId)) {
            $embed = 'https://www.tiktok.com/embed/v2/'.$postId;
            $label = 'TikTok';
            $isVideo = true;
        } else {
            return null;
        }

        $dog = is_array($raw['dog'] ?? null) ? $raw['dog'] : [];
        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $productId = filter_var($raw['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'platform' => $platform,
            'platform_label' => $label,
            'is_video' => $isVideo,
            'url' => $url,
            'embed_url' => $embed,
            'thumbnail_url' => $base.$thumb,
            'product_id' => $productId === false ? null : $productId,
            'product_name' => $text($raw['product_name'] ?? null, 100),
            'dog_breed' => $text($dog['breed'] ?? null, 32),
            'dog_age' => $text($dog['age'] ?? null, 16),
            'dog_size_label' => $text($dog['size_label'] ?? null, 16),
            'dog_stage_label' => $text($dog['stage_label'] ?? null, 16),
        ];
    }

    private static function env(string $key): ?string
    {
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key) ?: null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
