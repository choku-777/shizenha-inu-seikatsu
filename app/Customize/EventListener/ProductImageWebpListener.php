<?php

namespace Customize\EventListener;

use Doctrine\ORM\Event\PostPersistEventArgs;
use Eccube\Entity\ProductImage;
use Psr\Log\LoggerInterface;

/**
 * 商品画像（PNG/JPG）を自動的にWebPに変換するリスナー.
 *
 * EC-CUBE の ProductController では persist() がファイル移動より前に呼ばれるため、
 * prePersist では save_image にファイルが無い。flush() 時点（postPersist）では
 * ファイルが移動済みなので、ここで変換する.
 *
 * 安全方針:
 * - 変換失敗時は元画像をそのまま使う（アップロード自体は成功扱い）
 * - WebPファイルが正常に生成されたことを検証してから元画像を削除
 * - GD/imagewebp が無ければ何もしない
 */
class ProductImageWebpListener
{
    private const QUALITY = 82;
    private const SUPPORTED_EXTS = ['png', 'jpg', 'jpeg'];

    public function __construct(
        private string $projectDir,
        private LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ProductImage) {
            return;
        }

        $this->convert($entity, $args->getObjectManager());
    }

    private function convert(ProductImage $image, $em): void
    {
        $fileName = $image->getFileName();
        if (!$fileName) {
            return;
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_EXTS, true)) {
            return;
        }

        if (!function_exists('imagewebp') || !function_exists('imagecreatefrompng') || !function_exists('imagecreatefromjpeg')) {
            return;
        }

        $dir = $this->projectDir . '/html/upload/save_image';
        $srcPath = $dir . '/' . $fileName;
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            return;
        }

        $newFileName = preg_replace('/\.(png|jpg|jpeg)$/i', '.webp', $fileName);
        $destPath = $dir . '/' . $newFileName;

        try {
            $img = $ext === 'png'
                ? @imagecreatefrompng($srcPath)
                : @imagecreatefromjpeg($srcPath);
            if ($img === false) {
                throw new \RuntimeException('画像デコード失敗');
            }

            if ($ext === 'png') {
                imagepalettetotruecolor($img);
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            $ok = @imagewebp($img, $destPath, self::QUALITY);
            imagedestroy($img);

            if (!$ok) {
                throw new \RuntimeException('imagewebp 失敗');
            }
            if (!is_file($destPath) || filesize($destPath) < 100) {
                throw new \RuntimeException('出力ファイル不正');
            }

            // postPersistでは既にINSERT済みなので、直接UPDATEでfile_nameを更新
            $em->getConnection()->executeStatement(
                'UPDATE dtb_product_image SET file_name = ? WHERE id = ?',
                [$newFileName, $image->getId()]
            );
            // インメモリのエンティティも更新（同一リクエスト内で参照される場合のため）
            $image->setFileName($newFileName);

            // 元ファイル削除
            @unlink($srcPath);

            $this->logger->info('商品画像をWebPに変換しました', [
                'src' => $fileName,
                'dest' => $newFileName,
                'size' => filesize($destPath),
            ]);
        } catch (\Throwable $e) {
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
            $this->logger->warning('商品画像のWebP変換に失敗。元画像をそのまま使用します。', [
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
