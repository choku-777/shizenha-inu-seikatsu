<?php

namespace Customize\Twig\Extension;

use Customize\Service\AmbassadorPostProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * テンプレートからアンバサダー投稿の一覧を使う: {{ ambassador_posts(商品ID or null, 件数) }}
 * 取得で何が起きてもページ表示は止めない（空を返して欄ごと非表示にする）。
 */
class AmbassadorPostExtension extends AbstractExtension
{
    public function __construct(private AmbassadorPostProvider $provider)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ambassador_posts', [$this, 'posts']),
        ];
    }

    public function posts(?int $productId = null, int $limit = 6): array
    {
        try {
            return $this->provider->list($productId, $limit);
        } catch (\Throwable $e) {
            log_error('[AmbassadorPosts] 表示用の一覧を作れませんでした', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
