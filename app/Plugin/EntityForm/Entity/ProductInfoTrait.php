<?php

namespace Plugin\EntityForm\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;
use Eccube\Annotation\FormAppend;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * @EntityExtension("Eccube\Entity\Product")
 */
trait ProductInfoTrait
{
    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextareaType",
     *     options={"required": false, "label": "原材料", "attr": {"rows": 3}}
     * )
     */
    public $ingredients;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextareaType",
     *     options={"required": false, "label": "成分値", "attr": {"rows": 3}}
     * )
     */
    public $nutrition;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextType",
     *     options={"required": false, "label": "内容量"}
     * )
     */
    public $capacity;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextType",
     *     options={"required": false, "label": "賞味期限"}
     * )
     */
    public $expiry;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextareaType",
     *     options={"required": false, "label": "保存方法", "attr": {"rows": 3}}
     * )
     */
    public $storage;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextType",
     *     options={"required": false, "label": "原産国"}
     * )
     */
    public $origin_country;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextType",
     *     options={"required": false, "label": "カロリー（例: 約287kcal / 100g）"}
     * )
     */
    public $calorie;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\TextareaType",
     *     options={"required": false, "label": "給与量の目安", "attr": {"rows": 3}}
     * )
     */
    public $feeding_amount;
}
