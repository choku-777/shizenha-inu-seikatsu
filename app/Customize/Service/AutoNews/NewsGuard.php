<?php

namespace Customize\Service\AutoNews;

/**
 * 届いた原稿を公開してよいか確認する。AIへの指示だけに頼らず、ここでも機械的に止める。
 *
 * - invalid: お知らせを作らない（投稿IDの形式違い・保存できないほど長い）
 * - review:  下書き（非公開）で作り、人が確認する（禁止語・文字数・日付・根拠の不一致など）
 * - ok:      問題なし（自動公開がオンなら公開）
 *
 * 言葉の確認だけでは内容の正しさまでは保証できないため、根拠は材料タブのIDと照合する。
 */
class NewsGuard
{
    public const TITLE_MAX = 40;

    public const BODY_MIN = 60;

    public const BODY_MAX = 300;

    /** これを超える原稿は保存せずに拒否する（データベースの列を守る） */
    private const TITLE_HARD_MAX = 200;

    private const BODY_HARD_MAX = 2000;

    /** 効果効能（景品表示法・医薬品医療機器等法の観点）、競合、誇大表現 */
    private const BANNED_WORDS = [
        '治る', '治す', '治療', '治癒', '完治', '改善', '予防', '防ぐ', '効く', '効果', '効能', '病気', '症状', '疾患',
        '免疫', '薬', '癌', '腫瘍', 'アレルギー', 'デトックス', 'ダイエット', '痩せ', '長生き', '寿命',
        '健康いぬ生活', '利他フーズ', 'kenko', 'ケンコー',
        '絶対', '必ず', '最安', '最強', '日本一', 'no.1', 'no1', 'ナンバーワン', '世界一', '業界初',
    ];

    public static function isValidPostId(string $postId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{4,64}$/', $postId);
    }

    public function authenticate(string $given, string $expected): bool
    {
        return $expected !== '' && hash_equals($expected, trim($given));
    }

    /**
     * @param array{post_id:string,date:string,title:string,body:string,basis:string} $post
     * @param array<int,string>                                                       $materialIds 材料タブにある ID
     *
     * @return array{verdict:string,reasons:array<int,string>}
     */
    public function check(array $post, string $today, array $materialIds): array
    {
        $title = trim($post['title']);
        $body = trim($post['body']);

        if (!self::isValidPostId($post['post_id'])) {
            return ['verdict' => 'invalid', 'reasons' => ['投稿IDの形式が違います']];
        }
        if (mb_strlen($title) > self::TITLE_HARD_MAX || mb_strlen($body) > self::BODY_HARD_MAX) {
            return ['verdict' => 'invalid', 'reasons' => ['長すぎて保存できません']];
        }

        $reasons = [];
        if ($this->parseDate($post['date']) !== $today) {
            $reasons[] = '日付が今日ではありません';
        }
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            $reasons[] = 'タイトルは1〜'.self::TITLE_MAX.'文字';
        }
        if (mb_strlen($body) < self::BODY_MIN || mb_strlen($body) > self::BODY_MAX) {
            $reasons[] = '本文は'.self::BODY_MIN.'〜'.self::BODY_MAX.'文字';
        }

        // 根拠：材料タブにある ID だけを使っていること。過去のお知らせだけを根拠にしていないこと
        preg_match_all('/\b(?:amb|prod|news|manual)-[A-Za-z0-9]+\b/', $post['basis'], $m);
        $ids = array_values(array_unique($m[0]));
        $unknown = array_diff($ids, $materialIds);
        $topical = array_filter($ids, fn ($id) => !str_starts_with($id, 'news-'));
        if (!$ids) {
            $reasons[] = '根拠に材料のIDがありません';
        } elseif ($unknown) {
            $reasons[] = '材料に無いIDを根拠にしています：'.implode('、', $unknown);
        } elseif (!$topical) {
            $reasons[] = '新しい話題の材料がありません';
        }
        $normalized = $this->normalize($title."\n".$body);
        $nfkc = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($title."\n".$body, \Normalizer::FORM_KC) : mb_convert_kana($title."\n".$body, 'asKV');
        // 「spring」などに含まれる pr ではなく、独立した「PR」の表記があること
        if (array_filter($ids, fn ($id) => str_starts_with($id, 'amb-')) && !preg_match('/(?<![a-z])pr(?![a-z])/iu', $nfkc)) {
            $reasons[] = 'アンバサダーの紹介に「PR」の表記がありません';
        }

        if (preg_match('/https?:|www\.|[<>＜＞]|@everyone|@here|\{\{|\{%/iu', $title."\n".$body)) {
            $reasons[] = 'URL・HTMLなどは使えません';
        }
        foreach (self::BANNED_WORDS as $word) {
            if (str_contains($normalized, $this->normalize($word))) {
                $reasons[] = '使わない言葉：'.$word;
            }
        }

        return ['verdict' => $reasons ? 'review' : 'ok', 'reasons' => $reasons];
    }

    /**
     * すり抜け対策：全角半角をそろえ、見えない文字と空白を取り除き、小文字にする。
     */
    private function normalize(string $text): string
    {
        $text = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_KC) : mb_convert_kana($text, 'asKV');
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = (string) preg_replace('/[\s　・･\-ー_]+/u', '', $text);

        return mb_strtolower($text);
    }

    /**
     * YYYY-MM-DD（/ や . も可）を厳密に読む。存在しない日付は空を返す。
     */
    private function parseDate(string $value): string
    {
        if (!preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', trim($value), $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
}
