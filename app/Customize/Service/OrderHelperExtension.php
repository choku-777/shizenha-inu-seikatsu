<?php

namespace Customize\Service;

use Eccube\Entity\Master\OrderItemType;
use Eccube\Entity\Master\TaxDisplayType;
use Eccube\Service\OrderHelper;

/**
 * 商品・値引きの税表示区分を「税込（内税）」として扱うためのカスタマイズ.
 *
 * 商品登録価格を税込価格として運用するため、受注明細の税計算でも
 * 商品(PRODUCT)・値引き(DISCOUNT)を内税扱いにする.
 */
class OrderHelperExtension extends OrderHelper
{
    /**
     * {@inheritdoc}
     */
    public function getTaxDisplayType($OrderItemType)
    {
        if ($OrderItemType instanceof OrderItemType) {
            $OrderItemType = $OrderItemType->getId();
        }

        switch ($OrderItemType) {
            case OrderItemType::PRODUCT:
            case OrderItemType::DISCOUNT:
                // 商品・値引きを税込（内税）扱いにする
                return $this->entityManager->find(TaxDisplayType::class, TaxDisplayType::INCLUDED);
            case OrderItemType::DELIVERY_FEE:
            case OrderItemType::CHARGE:
            case OrderItemType::POINT:
                return $this->entityManager->find(TaxDisplayType::class, TaxDisplayType::INCLUDED);
            default:
                return $this->entityManager->find(TaxDisplayType::class, TaxDisplayType::INCLUDED);
        }
    }
}
