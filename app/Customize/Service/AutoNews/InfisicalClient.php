<?php

namespace Customize\Service\AutoNews;

/**
 * Infisical から秘密の値を受け取る（読み取り専用のマシンID）。
 * 値はメモリ上にだけ持ち、ファイルやログには書かない。
 */
class InfisicalClient
{
    private ?string $token = null;

    private array $cache = [];

    /**
     * @param bool $required false なら、登録が無いとき null を返す
     */
    public function get(string $key, bool $required = true): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $query = http_build_query([
            'workspaceId' => AutoNewsConfig::INFISICAL_PROJECT_ID,
            'environment' => AutoNewsConfig::INFISICAL_ENV,
            'secretPath' => '/',
        ]);
        [$code, $body] = Http::request('GET', AutoNewsConfig::INFISICAL_HOST.'/api/v3/secrets/raw/'.rawurlencode($key).'?'.$query, [
            'Authorization: Bearer '.$this->token(),
        ]);

        if ($code === 404 && !$required) {
            return $this->cache[$key] = null;
        }
        $value = Http::json($body)['secret']['secretValue'] ?? null;
        if ($code !== 200 || !is_string($value)) {
            throw new \RuntimeException("Infisical から {$key} を受け取れませんでした（HTTP {$code}）");
        }
        $value = trim($value);
        if ($value === '') {
            if (!$required) {
                return $this->cache[$key] = null;
            }
            throw new \RuntimeException("Infisical の {$key} が空です");
        }

        return $this->cache[$key] = $value;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $file = AutoNewsConfig::machineIdentityFile();
        $identity = is_readable($file) ? parse_ini_file($file) : false;
        if (!$identity || empty($identity['INFISICAL_CLIENT_ID']) || empty($identity['INFISICAL_CLIENT_SECRET'])) {
            throw new \RuntimeException('Infisical のログイン情報を読めません');
        }

        [$code, $body] = Http::request('POST', AutoNewsConfig::INFISICAL_HOST.'/api/v1/auth/universal-auth/login', [
            'Content-Type: application/json',
        ], json_encode([
            'clientId' => $identity['INFISICAL_CLIENT_ID'],
            'clientSecret' => $identity['INFISICAL_CLIENT_SECRET'],
        ]));
        unset($identity);

        $token = Http::json($body)['accessToken'] ?? null;
        if ($code !== 200 || !is_string($token)) {
            throw new \RuntimeException("Infisical にログインできませんでした（HTTP {$code}）");
        }

        return $this->token = $token;
    }
}
