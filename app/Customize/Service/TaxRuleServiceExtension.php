<?php

namespace Customize\Service;

use Eccube\Entity\ProductClass;
use Eccube\Service\TaxRuleService;

/**
 * 商品登録価格を「税込価格」として扱うためのカスタマイズ.
 *
 * - getTax(): 登録価格を税込とみなし、そこに含まれる消費税額を返す（内税計算）
 * - getPriceIncTax(): 登録価格がそのまま税込価格なので、加算せずそのまま返す
 */
class TaxRuleServiceExtension extends TaxRuleService
{
    /**
     * {@inheritdoc}
     *
     * 登録価格に含まれる消費税額を返す（内税）.
     */
    public function getTax($price, $product = null, $productClass = null, $pref = null, $country = null)
    {
        $TaxRule = $this->resolveTaxRule($product, $productClass, $pref, $country);

        return $this->calcTaxIncluded(
            $price,
            $TaxRule->getTaxRate(),
            $TaxRule->getRoundingType()->getId(),
            $TaxRule->getTaxAdjust()
        );
    }

    /**
     * {@inheritdoc}
     *
     * 登録価格がそのまま税込価格.
     */
    public function getPriceIncTax($price, $product = null, $productClass = null, $pref = null, $country = null)
    {
        return $price;
    }

    /**
     * 適用する課税規則を取得する（親クラスの getTax と同じロジック）.
     *
     * @return \Eccube\Entity\TaxRule
     */
    private function resolveTaxRule($product = null, $productClass = null, $pref = null, $country = null)
    {
        if ($this->BaseInfo->isOptionProductTaxRule() && $productClass) {
            if ($productClass instanceof ProductClass) {
                return $productClass->getTaxRule() ?: $this->taxRuleRepository->getByRule(null, null, $pref, $country);
            }

            return $this->taxRuleRepository->getByRule($product, $productClass, $pref, $country);
        }

        return $this->taxRuleRepository->getByRule(null, null, $pref, $country);
    }
}
