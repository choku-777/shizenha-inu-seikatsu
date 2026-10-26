<?php

namespace Customize\Service\AutoNews;

use Customize\Service\AmbassadorPostProvider;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\News;
use Eccube\Entity\Product;

/**
 * Dots が読む「材料」タブ（ウェブに公開）の中身を作る。もともとサイトで公開している情報だけを入れる。
 * 社内で書く休業日・発送予定などは「手動材料」タブ（非公開）に書いてもらい、ここで写す。
 * 自動処理は「手動材料」タブを読むだけで、書き換えない（更新に失敗しても消えない）。
 */
class MaterialsBuilder
{
    public const HEADER = ['種類', '日付', 'タイトル', '内容', 'URL', 'ID'];

    /** 社内で書く材料のタブ（ウェブには公開しない） */
    public const MANUAL_SHEET = '手動材料';

    public const MANUAL_HEADER = ['日付', 'タイトル', '内容', 'URL'];

    private const NEW_PRODUCT_DAYS = 30;

    private const PAST_NEWS_COUNT = 10;

    public function __construct(
        private AmbassadorPostProvider $ambassadorPosts,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<int, array<int, string>> $manualRows 「手動材料」タブ（見出しを除く。列は 日付／タイトル／内容／URL）
     *
     * @return array<int, array<int, string>>
     */
    public function build(array $manualRows): array
    {
        $rows = [];

        foreach ($manualRows as $row) {
            $row = array_map(fn ($v) => trim((string) $v), array_pad($row, 4, ''));
            if ($row[1] === '' && $row[2] === '') {
                continue;
            }
            $rows[] = ['手動', $row[0], $row[1], $row[2], $row[3], 'manual-'.substr(sha1(implode("\n", array_slice($row, 0, 4))), 0, 10)];
        }

        foreach ($this->ambassadorPosts->list(null, 50) as $post) {
            $dog = implode('・', array_filter([$post['dog_breed'], $post['dog_age'], $post['dog_size_label']]));
            $rows[] = [
                'アンバサダー動画',
                (string) ($post['approved_at'] ?? ''),
                $post['product_name'] ? $post['product_name'].'の投稿' : 'アンバサダーの投稿',
                trim(($post['is_video'] ? '動画' : '写真').'の投稿（'.$post['platform_label'].'）'.($dog ? '／'.$dog : '')),
                AutoNewsConfig::SITE_URL.'/',
                'amb-'.substr(sha1($post['url']), 0, 10),
            ];
        }

        $since = new \DateTime('-'.self::NEW_PRODUCT_DAYS.' days');
        $products = $this->em->createQueryBuilder()
            ->select('p')->from(Product::class, 'p')
            ->where('p.Status = :status')->andWhere('p.create_date >= :since')
            ->setParameter('status', ProductStatus::DISPLAY_SHOW)->setParameter('since', $since)
            ->orderBy('p.create_date', 'DESC')
            ->getQuery()->getResult();
        foreach ($products as $product) {
            $rows[] = [
                '新商品',
                $product->getCreateDate()->format('Y-m-d'),
                (string) $product->getName(),
                '',
                AutoNewsConfig::SITE_URL.'/products/detail/'.$product->getId(),
                'prod-'.$product->getId(),
            ];
        }

        // 公開日時を過ぎたものだけ（公開予約中のお知らせを、公開している材料に出さない）
        $news = $this->em->createQueryBuilder()
            ->select('n')->from(News::class, 'n')
            ->where('n.visible = true')->andWhere('n.publish_date <= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('n.publish_date', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults(self::PAST_NEWS_COUNT)
            ->getQuery()->getResult();
        foreach ($news as $item) {
            $rows[] = [
                '過去のお知らせ',
                $item->getPublishDate() ? $item->getPublishDate()->format('Y-m-d') : '',
                (string) $item->getTitle(),
                '同じ話題・同じタイトルは書かない',
                '',
                'news-'.$item->getId(),
            ];
        }

        return $rows;
    }

    /**
     * 「ルール」タブの初期内容（Dots が毎回読む）。
     *
     * @return array<int, array<int, string>>
     */
    public static function defaultRules(): array
    {
        return [
            ['項目', '内容'],
            ['目的', '馬肉のドッグフード通販「うましっぽ」（https://umashippo.jp/）のトップページに載せるお知らせを、1日1件まで書く。読む人は犬・猫の飼い主。'],
            ['書いてよい話題', '材料タブにある事実だけ。①新しく掲載したアンバサダー動画の紹介 ②新商品 ③種類が「手動」の行（休業日・発送予定など社内からのお知らせ）'],
            ['書かない日', '材料タブに、過去のお知らせと重複しない新しい話題が無い日は、フォームを送信しない。迷ったら送信しない'],
            ['禁止（効果効能）', '病気・健康への効果や効能を書かない（治る・改善・予防・効く・免疫・アレルギー・ダイエット・長生き など）。体の変化を約束する表現も書かない'],
            ['禁止（そのほか）', '他社の名前や他社との比較、材料に無い価格・セール・在庫・数字、断定や誇大な表現（絶対・必ず・最安・No.1・日本一）、URL・HTML、個人名・SNSのアカウント名'],
            ['アンバサダー動画の紹介', '本文に「アンバサダーさんのPR投稿」であることを書く（「PR」の文字を必ず入れる）。犬種・商品名は材料のとおりに書き、材料に無い内容を足さない。動画はトップページの「うましっぽのある毎日」で見られる、と案内してよい'],
            ['文体', 'です・ます調。やさしく落ち着いた文。1〜3文で短く。絵文字は使わない'],
            ['文字数', 'タイトル'.NewsGuard::TITLE_MAX.'文字以内、本文'.NewsGuard::BODY_MIN.'〜'.NewsGuard::BODY_MAX.'文字'],
            ['重複', '材料タブの「過去のお知らせ」と同じ話題・同じタイトルは書かない'],
            ['送信のしかた', 'フォームの各欄に入力して送信。投稿IDは「送信日YYYYMMDD-連番」（例：20261028-1）。日付は送信日をYYYY-MM-DDで。根拠には使った材料のIDを書く（例：amb-1a2b3c4d5e）。材料タブに無いIDは書かない。合言葉は指定の文字列'],
        ];
    }
}
