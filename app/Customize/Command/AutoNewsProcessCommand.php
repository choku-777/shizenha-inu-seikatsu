<?php

namespace Customize\Command;

use Customize\Service\AutoNews\ActionToken;
use Customize\Service\AutoNews\AutoNewsConfig;
use Customize\Service\AutoNews\DiscordNotifier;
use Customize\Service\AutoNews\GoogleSheetsClient;
use Customize\Service\AutoNews\InfisicalClient;
use Customize\Service\AutoNews\NewsGuard;
use Customize\Service\AutoNews\ProcessedStore;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\News;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 毎朝9:00（遅れた分は12:00）：Dots がフォームから送った原稿を確認し、お知らせを作る。
 *
 * - 合言葉が違う → 作らない。投稿IDは使わず別扱いで記録（第三者が正規の投稿IDをふさげないように）
 * - 投稿IDの形式違い・長すぎる → 作らない（拒否）
 * - 禁止語・文字数・日付・根拠の不一致など → 下書き（非公開）で作り、人が確認
 * - 問題なし → NEWS_AUTO_PUBLISH=1 なら公開、そうでなければ下書き（お試し運用）
 *
 * 二重作成を防ぐため、お知らせを作る前にサーバー側へ「作成中」を記録し、作った後に結果を記録する。
 * 処理結果タブへの記録と Discord 通知は、失敗しても次回の実行でやり直す。
 */
class AutoNewsProcessCommand extends Command
{
    protected static $defaultName = 'umashippo:news:process';

    /** フォームの質問名（列の位置ではなく見出しの名前で読む） */
    private const FORM_COLUMNS = [
        'received_at' => 'タイムスタンプ',
        'post_id' => '投稿ID',
        'date' => '日付',
        'title' => 'タイトル',
        'body' => '本文',
        'basis' => '根拠',
        'passphrase' => '合言葉',
    ];

    private const STATUS_LABELS = [
        'published' => '公開',
        'review' => '下書き（要確認）',
        'trial' => '下書き（お試し運用）',
        'rejected' => '拒否',
        'held' => '保留（本日の上限）',
        'unknown' => '要確認（作成されたか不明）',
    ];

    public function __construct(
        private GoogleSheetsClient $sheets,
        private InfisicalClient $infisical,
        private NewsGuard $guard,
        private DiscordNotifier $discord,
        private ActionToken $tokens,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('お知らせ自動更新：届いた原稿を確認してお知らせを作る');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = new ProcessedStore();
        if (!$store->acquire()) {
            $output->writeln('別の処理が実行中のため終了します');

            return Command::SUCCESS;
        }

        try {
            // サーバー側の記録が無いのに処理結果タブには記録がある＝記録が消えた。1日の上限や二重作成の判定が
            // 効かなくなるため、自動では進めない（var/auto_news/state.json をバックアップから戻してから再実行）
            [$recordedIds, $hasAnyResult] = $this->recordedPostIds();
            if (!$store->all() && $hasAnyResult) {
                throw new \RuntimeException('サーバー側の処理記録（var/auto_news/state.json）が見つかりません。復旧するまで自動処理を止めています');
            }

            // 公開してから通知できない、を防ぐため、署名鍵を先に確かめる
            $this->tokens->assertReady();

            $this->resumeUnfinished($store, $output);

            $passphrase = (string) $this->infisical->get('NEWS_FORM_PASSPHRASE');
            $autoPublish = $this->infisical->get('NEWS_AUTO_PUBLISH', false) === '1';
            $today = date('Y-m-d');
            $materialIds = array_values(array_filter(array_map(fn ($r) => trim((string) ($r[0] ?? '')), $this->sheets->get(AutoNewsConfig::SHEET_MATERIALS, 'F2:F'))));
            $columns = $this->formColumns();

            $handled = 0;
            foreach ($this->sheets->get(AutoNewsConfig::SHEET_FORM, 'A2:Z') as $row) {
                $post = [];
                foreach ($columns as $name => $index) {
                    $post[$name] = (string) ($row[$index] ?? '');
                }
                $post['post_id'] = trim($post['post_id']);
                $rowKey = 'row-'.substr(sha1(json_encode($row)), 0, 16);

                // 合言葉が違う送信は投稿IDを使わない（正規の投稿IDを先に使われてふさがれるのを防ぐ）
                if (!$this->guard->authenticate($post['passphrase'], $passphrase)) {
                    if (!$store->get($rowKey)) {
                        // 通知は1件ずつではなく最後にまとめて送る（batch）
                        $store->put($rowKey, ['status' => 'rejected', 'reason' => '合言葉が一致しません', 'received_at' => $post['received_at'], 'sheet' => false, 'notified' => false, 'batch' => true]);
                        $this->writeResult($store, $rowKey, $output);
                    }
                    continue;
                }

                $key = NewsGuard::isValidPostId($post['post_id']) ? $post['post_id'] : $rowKey;
                if ($store->get($key) || isset($recordedIds[$key])) {
                    continue;
                }

                $this->handle($post, $key, $store, $autoPublish, $today, $materialIds, $output);
                ++$handled;
            }

            $this->notifyRejectedBatch($store, $output);
            $output->writeln($handled.'件の原稿を処理しました');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            $this->notifyText("【お知らせ自動更新】処理に失敗しました。サーバーの記録を確認してください。\n".$e->getMessage(), $output);

            return Command::FAILURE;
        } finally {
            $store->release();
        }
    }

    private function handle(array $post, string $key, ProcessedStore $store, bool $autoPublish, string $today, array $materialIds, OutputInterface $output): void
    {
        $check = $this->guard->check($post, $today, $materialIds);
        $base = [
            'received_at' => $post['received_at'],
            'title' => trim($post['title']),
            'body' => trim($post['body']),
            'basis' => trim($post['basis']),
            'reasons' => $check['reasons'],
            'sheet' => false,
            'notified' => false,
        ];

        if ($check['verdict'] === 'invalid') {
            $store->put($key, $base + ['status' => 'rejected', 'reason' => implode('／', $check['reasons'])]);
            $this->finish($store, $key, $output);

            return;
        }
        if ($store->createdToday() >= AutoNewsConfig::DAILY_LIMIT) {
            $store->put($key, $base + ['status' => 'held', 'reason' => '1日'.AutoNewsConfig::DAILY_LIMIT.'件までのため自動では作りません。必要なら管理画面で作成してください']);
            $this->finish($store, $key, $output);

            return;
        }

        $status = $check['verdict'] === 'ok' ? ($autoPublish ? 'published' : 'trial') : 'review';

        // 作る前に「作成中」を記録（この後で止まっても、次回は作り直さずに人へ確認を依頼する）
        $store->put($key, $base + ['status' => 'pending']);

        $now = new \DateTime();
        $news = new News();
        $news->setTitle(mb_substr($base['title'], 0, 255));
        // 表示テンプレートが本文を HTML としてそのまま出すため、保存前に必ず無害化する
        $news->setDescription(htmlspecialchars($base['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $news->setPublishDate($now);
        $news->setUrl(null);
        $news->setLinkMethod(false);
        $news->setVisible($status === 'published');
        $news->setCreateDate($now);
        $news->setUpdateDate($now);
        $this->em->persist($news);
        $this->em->flush();

        $store->put($key, [
            'status' => $status,
            'news_id' => $news->getId(),
            'published_at' => $status === 'published' ? $now->format('Y-m-d H:i') : '',
            'reason' => implode('／', $check['reasons']),
        ]);
        $this->finish($store, $key, $output);
    }

    /**
     * 前回までに終わっていない作業（処理結果タブへの記録・通知、作成中のまま止まったもの）を片付ける。
     */
    private function resumeUnfinished(ProcessedStore $store, OutputInterface $output): void
    {
        foreach ($store->all() as $key => $record) {
            if (($record['status'] ?? '') === 'pending') {
                $store->put($key, ['status' => 'unknown', 'reason' => '前回の処理が途中で止まりました。管理画面のお知らせ管理で、同じタイトルのお知らせがあるか確認してください', 'sheet' => false, 'notified' => false]);
            }
            $record = $store->get($key);
            if (empty($record['sheet']) || empty($record['notified'])) {
                $this->finish($store, $key, $output);
            }
        }
    }

    /**
     * 処理結果タブへの記録と Discord 通知。それぞれ成功したら記録し、失敗したら次回やり直す。
     */
    private function finish(ProcessedStore $store, string $key, OutputInterface $output): void
    {
        $this->writeResult($store, $key, $output);

        $record = (array) $store->get($key);
        if (!empty($record['notified']) || !empty($record['batch'])) {
            return;
        }
        try {
            $this->discord->send($this->message($key, $record));
            $store->put($key, ['notified' => true]);
        } catch (\Throwable $e) {
            $output->writeln('<error>Discord への通知に失敗（次回やり直します）：'.$e->getMessage().'</error>');
        }
    }

    /**
     * 合言葉が一致しない送信を、まとめて1通で知らせる（送れなければ次回やり直す）。
     */
    private function notifyRejectedBatch(ProcessedStore $store, OutputInterface $output): void
    {
        $keys = [];
        foreach ($store->all() as $key => $record) {
            if (!empty($record['batch']) && empty($record['notified'])) {
                $keys[] = $key;
            }
        }
        if (!$keys) {
            return;
        }
        try {
            $this->discord->send('【お知らせ自動更新】合言葉が一致しない送信が'.count($keys).'件ありました。内容は公開していません。');
            foreach ($keys as $key) {
                $store->put($key, ['notified' => true]);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Discord への通知に失敗（次回やり直します）：'.$e->getMessage().'</error>');
        }
    }

    private function writeResult(ProcessedStore $store, string $key, OutputInterface $output): void
    {
        $record = (array) $store->get($key);
        if (!empty($record['sheet'])) {
            return;
        }
        try {
            $this->sheets->append(AutoNewsConfig::SHEET_RESULTS, 'A:F', [[
                $key,
                (string) ($record['received_at'] ?? ''),
                self::STATUS_LABELS[$record['status']] ?? (string) $record['status'],
                (string) ($record['reason'] ?? ''),
                isset($record['news_id']) ? (string) $record['news_id'] : '',
                (string) ($record['published_at'] ?? ''),
            ]]);
            $store->put($key, ['sheet' => true]);
        } catch (\Throwable $e) {
            $output->writeln('<error>処理結果タブへの記録に失敗（次回やり直します）：'.$e->getMessage().'</error>');
        }
    }

    private function message(string $key, array $record): string
    {
        $status = (string) $record['status'];
        $lines = [match ($status) {
            'published' => '【お知らせ自動更新】公開しました',
            'trial' => '【お知らせ自動更新】下書きを作りました（お試し運用中のため未公開）',
            'review' => '【お知らせ自動更新】下書きを作りました。確認が必要です',
            'held' => '【お知らせ自動更新】本日の上限に達したため、自動では作りませんでした',
            'unknown' => '【お知らせ自動更新】作成されたか分からない原稿があります。確認してください',
            default => '【お知らせ自動更新】原稿を受け付けませんでした',
        }];
        // 操作リンクと確認点を先に載せ、本文・根拠は短く切る（通知の文字数上限で大事な部分が切れないように）
        if (!empty($record['news_id']) && in_array($status, ['published', 'review', 'trial'], true)) {
            $lines[] = $status === 'published'
                ? '非公開にする：'.$this->tokens->url('hide', (int) $record['news_id'])
                : '公開する：'.$this->tokens->url('publish', (int) $record['news_id']);
        }
        if (!empty($record['reason'])) {
            $lines[] = '確認が必要な点：'.mb_substr((string) $record['reason'], 0, 300);
        }
        $lines[] = '投稿ID：'.$key;
        foreach (['title' => ['タイトル', 100], 'body' => ['本文', 700], 'basis' => ['根拠', 200]] as $field => [$label, $max]) {
            if (!empty($record[$field])) {
                $text = (string) $record[$field];
                $lines[] = $label.'：'.(mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…（省略）' : $text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 処理結果タブの記録。
     * 1つ目：記録済みの投稿ID（拒否以外。サーバー側の記録を失ったときの二重作成防止）
     * 2つ目：拒否も含めて何か1件でも記録があるか（サーバー側の記録が消えたことの検知用）
     *
     * @return array{0: array<string, true>, 1: bool}
     */
    private function recordedPostIds(): array
    {
        $ids = [];
        $hasAny = false;
        foreach ($this->sheets->get(AutoNewsConfig::SHEET_RESULTS, 'A2:C') as $row) {
            $id = trim((string) ($row[0] ?? ''));
            if ($id === '') {
                continue;
            }
            $hasAny = true;
            if (trim((string) ($row[2] ?? '')) !== self::STATUS_LABELS['rejected']) {
                $ids[$id] = true;
            }
        }

        return [$ids, $hasAny];
    }

    /**
     * @return array<string, int> 項目名 => 列番号
     */
    private function formColumns(): array
    {
        $header = array_map(fn ($v) => trim((string) $v), $this->sheets->get(AutoNewsConfig::SHEET_FORM, 'A1:Z1')[0] ?? []);
        $columns = [];
        foreach (self::FORM_COLUMNS as $name => $label) {
            $index = array_search($label, $header, true);
            if ($index === false) {
                throw new \RuntimeException('フォームの回答タブに「'.$label.'」の列がありません');
            }
            $columns[$name] = $index;
        }

        return $columns;
    }

    private function notifyText(string $message, OutputInterface $output): void
    {
        try {
            $this->discord->send($message);
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
        }
    }
}
