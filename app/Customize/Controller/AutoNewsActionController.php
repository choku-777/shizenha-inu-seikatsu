<?php

namespace Customize\Controller;

use Customize\Service\AutoNews\ActionToken;
use Eccube\Controller\AbstractController;
use Eccube\Entity\News;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Discord の通知に載せた「非公開にする／公開する」リンクの受け口。
 * Discord はリンクの中身を自動で読みに来るため、開いただけ（GET）では何も変えず、
 * 確認画面のボタン（POST）を押したときだけ実行する。リンクは署名付き・期限付き。
 * 署名は URL の # 以降にあり、サーバーには POST の本文でだけ届く（ログに残さないため）。
 * 署名を確かめるまでは、お知らせの中身（下書きのタイトルなど）は表示しない。
 */
class AutoNewsActionController extends AbstractController
{
    public function __construct(private ActionToken $tokens)
    {
    }

    /**
     * @Route("/auto-news/{action}/{id}/{expires}", name="auto_news_action",
     *     requirements={"action"="hide|publish", "id"="\d+", "expires"="\d+"},
     *     methods={"GET", "POST"})
     */
    public function action(Request $request, string $action, int $id, int $expires): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->page(null, null, $action);
        }

        $signature = (string) $request->request->get('signature', '');
        if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return $this->page('このリンクは無効です。Discord の通知から開き直してください。', null, $action, 403);
        }

        try {
            $valid = $this->tokens->verify($action, $id, $expires, $signature);
        } catch (\Throwable $e) {
            // 署名鍵を受け取れなかった（Infisical の障害など）
            return $this->page('一時的に確認できませんでした。時間をおいてもう一度お試しください。', null, $action, 503);
        }
        if (!$valid) {
            return $this->page('このリンクは無効か、有効期限が切れています。管理画面のお知らせ管理から操作してください。', null, $action, 403);
        }

        $news = $this->entityManager->find(News::class, $id);
        if (!$news) {
            return $this->page('お知らせが見つかりません。削除された可能性があります。', null, $action, 404);
        }

        $news->setVisible($action === 'publish');
        $news->setUpdateDate(new \DateTime());
        $this->entityManager->flush();

        return $this->page($action === 'publish' ? '公開しました。' : '非公開にしました。', $news, $action, 200, true);
    }

    private function page(?string $message, ?News $news, string $action, int $status = 200, bool $done = false): Response
    {
        $response = $this->render('AutoNews/action.twig', [
            'message' => $message,
            'News' => $news,
            'action' => $action,
            'done' => $done,
        ]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
