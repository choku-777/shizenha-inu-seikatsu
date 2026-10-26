<?php

namespace Customize\Service\AutoNews;

/**
 * お知らせ自動更新（OpenAI Dots → Googleフォーム → このサイト）の固定設定。
 * 秘密の値はここに置かず、Infisical から実行のたびに受け取る（InfisicalClient）。
 */
final class AutoNewsConfig
{
    public const SITE_URL = 'https://umashippo.jp';

    public const SPREADSHEET_ID = '1_jkuVvcge5MVZhGu1N3m0swbA5gaAINI-7cpDpuScNw';

    public const SHEET_FORM = 'フォームの回答 1';

    public const SHEET_MATERIALS = '材料';

    public const SHEET_RULES = 'ルール';

    public const SHEET_RESULTS = '処理結果';

    public const INFISICAL_HOST = 'https://app.infisical.com';

    public const INFISICAL_PROJECT_ID = '55f93a32-30bd-41f6-96cc-d347fd6be59f';

    public const INFISICAL_ENV = 'prod';

    /** 1日に作るお知らせの上限（公開・下書きを合わせて） */
    public const DAILY_LIMIT = 1;

    /** 取り下げ・公開リンクの有効期間（日） */
    public const ACTION_LINK_DAYS = 7;

    /**
     * サーバーが Infisical に入るためのログイン情報の置き場所（Webから見えないホーム配下）。
     * Webの実行時は HOME が無いことがあるので、実行ユーザーのホームを引く。
     */
    public static function machineIdentityFile(): string
    {
        $home = getenv('HOME') ?: '';
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }

        return rtrim($home, '/').'/.config/infisical-umashippo/machine-identity.env';
    }
}
