<?php

namespace Customize\Service\AutoNews;

/**
 * 社内の Discord に処理結果を知らせる。誰かへの一斉通知（メンション）は無効にする。
 */
class DiscordNotifier
{
    public function __construct(private InfisicalClient $infisical)
    {
    }

    public function send(string $message): void
    {
        $url = $this->infisical->get('DISCORD_WEBHOOK_URL');
        if (!preg_match('#^https://(discord|discordapp)\.com/api/webhooks/\d+/[\w-]+$#', $url)) {
            throw new \RuntimeException('DISCORD_WEBHOOK_URL の形式が想定と違います');
        }

        [$code, $body] = Http::request('POST', $url.'?wait=true', ['Content-Type: application/json'], json_encode([
            'content' => mb_substr($message, 0, 1900),
            'allowed_mentions' => ['parse' => []],
        ]));
        if ($code < 200 || $code >= 300 || empty(Http::json($body)['id'])) {
            throw new \RuntimeException("Discord への通知に失敗しました（HTTP {$code}）");
        }
    }
}
