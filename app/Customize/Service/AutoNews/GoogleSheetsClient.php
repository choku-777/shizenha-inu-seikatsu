<?php

namespace Customize\Service\AutoNews;

/**
 * Google スプレッドシートの読み書き（サービスアカウント）。
 * 鍵は Infisical の GOOGLE_SERVICE_ACCOUNT_JSON から受け取る。
 * 書き込みは RAW（数式として解釈させない）。
 */
class GoogleSheetsClient
{
    private ?string $token = null;

    private int $tokenExpires = 0;

    public function __construct(private InfisicalClient $infisical)
    {
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function get(string $sheet, string $range): array
    {
        [$code, $body] = $this->call('GET', $this->valuesUrl($sheet, $range));
        $this->assertOk($code, $body, '読み取り');

        return Http::jsonStrict($body)['values'] ?? [];
    }

    public function update(string $sheet, string $range, array $rows): void
    {
        [$code, $body] = $this->call('PUT', $this->valuesUrl($sheet, $range).'?valueInputOption=RAW', json_encode([
            'values' => $rows,
        ]));
        $this->assertOk($code, $body, '書き込み');
        if (!isset(Http::jsonStrict($body)['updatedRange'])) {
            throw new \RuntimeException('スプレッドシートの書き込み結果を確認できません');
        }
    }

    public function append(string $sheet, string $range, array $rows): void
    {
        [$code, $body] = $this->call('POST', $this->valuesUrl($sheet, $range).':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', json_encode([
            'values' => $rows,
        ]));
        $this->assertOk($code, $body, '追記');
        if (!isset(Http::jsonStrict($body)['updates']['updatedRange'])) {
            throw new \RuntimeException('スプレッドシートの追記結果を確認できません');
        }
    }

    public function clear(string $sheet, string $range): void
    {
        [$code, $body] = $this->call('POST', $this->valuesUrl($sheet, $range).':clear', '{}');
        $this->assertOk($code, $body, '消去');
    }

    private function valuesUrl(string $sheet, string $range): string
    {
        $a1 = "'".str_replace("'", "''", $sheet)."'!".$range;

        return 'https://sheets.googleapis.com/v4/spreadsheets/'.AutoNewsConfig::SPREADSHEET_ID.'/values/'.rawurlencode($a1);
    }

    private function call(string $method, string $url, ?string $body = null): array
    {
        return Http::request($method, $url, [
            'Authorization: Bearer '.$this->token(),
            'Content-Type: application/json',
        ], $body, 20, 8 * 1024 * 1024);
    }

    /**
     * タブが無いとき（Sheets は 400 を返す）は空として扱いたい読み取り用。
     *
     * @return array<int, array<int, string>>|null タブが無ければ null
     */
    public function getIfExists(string $sheet, string $range): ?array
    {
        [$code, $body] = $this->call('GET', $this->valuesUrl($sheet, $range));
        if ($code === 400 && str_contains((string) (Http::json($body)['error']['message'] ?? ''), 'Unable to parse range')) {
            return null;
        }
        $this->assertOk($code, $body, '読み取り');

        return Http::jsonStrict($body)['values'] ?? [];
    }

    private function assertOk(int $code, string $body, string $what): void
    {
        if ($code < 200 || $code >= 300) {
            $message = Http::json($body)['error']['message'] ?? '';
            throw new \RuntimeException("スプレッドシートの{$what}に失敗しました（HTTP {$code} {$message}）");
        }
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return $this->token;
        }

        $account = json_decode($this->infisical->get('GOOGLE_SERVICE_ACCOUNT_JSON'), true);
        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new \RuntimeException('GOOGLE_SERVICE_ACCOUNT_JSON の形式が想定と違います');
        }

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        if (!openssl_sign($jwt, $signature, $account['private_key'], 'sha256WithRSAEncryption')) {
            throw new \RuntimeException('Google の鍵で署名できませんでした');
        }
        unset($account);

        [$code, $body] = Http::request('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt.'.'.$b64($signature),
        ]));
        $data = Http::json($body);
        if ($code !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException("Google にログインできませんでした（HTTP {$code}）");
        }

        $this->token = $data['access_token'];
        $this->tokenExpires = $now + (int) ($data['expires_in'] ?? 3600);

        return $this->token;
    }
}
