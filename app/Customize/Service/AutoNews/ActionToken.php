<?php

namespace Customize\Service\AutoNews;

/**
 * Discord に載せる「非公開にする／公開する」リンクの署名。
 * 推測できない文字列で、そのお知らせ・その操作・有効期限にだけ使える。
 * 署名は URL の「#」より後ろに置く。この部分はブラウザからサーバーへ送られないため、
 * サーバーのアクセスログやアプリのログに残らない（確認画面のボタンで POST するときだけ送る）。
 */
class ActionToken
{
    public const ACTIONS = ['hide', 'publish'];

    public function __construct(private InfisicalClient $infisical)
    {
    }

    public function url(string $action, int $newsId): string
    {
        $expires = time() + AutoNewsConfig::ACTION_LINK_DAYS * 86400;

        return AutoNewsConfig::SITE_URL.'/auto-news/'.$action.'/'.$newsId.'/'.$expires.'#'.$this->sign($action, $newsId, $expires);
    }

    /**
     * 署名鍵が使える状態か（お知らせを作る前に確かめ、公開してから通知できないのを防ぐ）。
     */
    public function assertReady(): void
    {
        $this->sign('hide', 0, 0);
    }

    public function verify(string $action, int $newsId, int $expires, string $signature): bool
    {
        return in_array($action, self::ACTIONS, true)
            && $expires >= time()
            && hash_equals($this->sign($action, $newsId, $expires), $signature);
    }

    private function sign(string $action, int $newsId, int $expires): string
    {
        $secret = (string) $this->infisical->get('NEWS_ACTION_SECRET');
        if (strlen($secret) < 32) {
            // 空や短い鍵では、誰でもリンクを作れてしまうため動かさない
            throw new \RuntimeException('NEWS_ACTION_SECRET が短すぎます');
        }

        return hash_hmac('sha256', $action.':'.$newsId.':'.$expires, $secret);
    }
}
