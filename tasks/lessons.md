# Lessons Learned

## EC-CUBE proxy entity cache (2026-04-03)
- `app/proxy/entity/` はEC-CUBEがEntityExtensionから自動生成するプロキシキャッシュ
- プラグインの Entity Trait を変更した場合、プロキシ再生成が必須
- XServer (SSH不可) では一時PHPスクリプト経由で `bin/console eccube:generate:proxies` を実行
- スクリプトのルートは `dirname(__DIR__)` ではなく `public_html/` 配下だった
- **セキュリティ**: 一時スクリプトは実行後に必ず削除

## @FormAppend アノテーション (2026-04-03)
- `auto_render=true` だけでは不十分。`type` と `options`（label等）が必要
- 正しい例: `@FormAppend(auto_render=true, type="\Symfony\Component\Form\Extension\Core\Type\TextareaType", options={"required": false, "label": "原材料"})`
- `form_theme` だけでは管理画面にフィールドが表示されない

## EC-CUBEプラグインの Web管理画面インストール失敗 (2026-05-12)
- **症状**: ルミーズ決済プラグインを管理画面からインストール → composer 失敗 → サイト500/503
- **原因**: XServer共有ホストでの composer install が途中で停止（タイムアウト/メモリ）
  - composer.json は更新済み
  - autoload_files.php に新パッケージへの参照追加済み
  - **但しパッケージ本体のダウンロードは未完了**
  - その上 メンテナンスモードもON のまま放置
- **二次被害**: Api42 プラグインの依存パッケージ（league/oauth2-server-bundle等）も巻き添えで消失
- **復旧手順（順番重要）**:
  1. SSHで `composer install --no-plugins --no-scripts --optimize-autoloader`（PluginInstallerの uninstall block 回避）
  2. 不足依存パッケージは個別に `composer require`
  3. `rm -rf var/cache/* app/proxy/entity/src` → `bin/console eccube:generate:proxies`
  4. 管理画面でメンテナンスモード解除
- **再発防止**:
  - プラグイン入れる前に `tar czf vendor_backup.tar.gz vendor` でバックアップ必須
  - Web管理画面ではなく SSH + `composer require ec-cube/プラグイン名` を使う
  - XServer の SSH 鍵は `~/.ssh/xs812447.key`、port 10022, user xs812447@sv16737.xserver.jp

## ルミーズ決済プラグイン関連 (2026-05-13)
- **有効化時 FK エラー**: 旧プラグイン（EccubePaymentLite42, AmazonPayV2）の外部キーが dtb_order/dtb_delivery/dtb_product_class に残存 → Ruumizのスキーマ更新が `Cannot drop index ... needed in a foreign key constraint` で失敗。`ALTER TABLE ... DROP FOREIGN KEY ...` で1つずつ削除して解消
- **チェックアウト500エラー**: Ruumizの `redirect.twig`（決済中継ページ）が `default_frame.twig` → `meta.twig` を読み、`/shopping/checkout` には Page エンティティが無いため `Page.url` が空文字 → `url()` 生成で例外。`app/template/default/meta.twig` を上書きし `and Page.url is not empty` を追加して対処

## メールが届かない (2026-05-13)
- **症状**: 注文確認メール等が届かない
- **原因**: `.env` の `MAILER_DSN` が Gmail SMTP（`main@829109.jp`）だが、通常パスワードを使用 → Gmailは2段階認証アカウントだとアプリパスワード必須（`534-5.7.9 Application-specific password required`）
- **解決**: `main@829109.jp` 本人のGoogleアカウントで2段階認証ON → `myaccount.google.com/apppasswords` でアプリパスワード生成 → `.env` を `MAILER_DSN=smtp://main%40829109.jp:【アプリパスワード】@smtp.gmail.com:587?auth_mode=plain` に更新 → キャッシュクリア
- アプリパスワードはWorkspace管理コンソールでは作れない（本人アカウント設定のみ）
