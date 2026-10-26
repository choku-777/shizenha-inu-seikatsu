<?php

namespace Customize\Service\AutoNews;

/**
 * 外部サービスとの通信。リダイレクトはたどらず、受信量に上限を付ける。
 */
final class Http
{
    /**
     * @return array{0:int,1:string} [HTTPステータス, 本文]
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15, int $maxBytes = 2097152): array
    {
        $buffer = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$tooLarge, $maxBytes) {
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // 上限を超えたら受信をやめる
                }

                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw new \RuntimeException('受信データが大きすぎます: '.parse_url($url, PHP_URL_HOST));
        }
        // 途中で切れた・時間切れの通信を、受け取った分だけで成功扱いにしない
        if ($ok === false || $errno !== 0 || $code === 0) {
            throw new \RuntimeException('通信に失敗しました: '.parse_url($url, PHP_URL_HOST).'（cURL '.$errno.'）');
        }

        return [$code, $buffer];
    }

    /**
     * 失敗時の本文など、形式が保証されないものの読み取り用（読めなければ空）。
     */
    public static function json(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 成功レスポンスの読み取り用。JSON として読めなければ失敗にする。
     */
    public static function jsonStrict(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('応答の形式が想定と違います');
        }

        return $data;
    }
}
