<?php

namespace Plugin\EntityForm\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;
use Eccube\Annotation\FormAppend;

/**
 * @EntityExtension("Eccube\Entity\Product")
 */
trait ProductInfoTrait
{
    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(auto_render=true, form_theme="EntityForm/Form/product_info.twig")
     */
    public $ingredients;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(auto_render=true, form_theme="EntityForm/Form/product_info.twig")
     */
    public $nutrition;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @FormAppend(auto_render=true, form_theme="EntityForm/Form/product_info.twig")
     */
    public $capacity;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @FormAppend(auto_render=true, form_theme="EntityForm/Form/product_info.twig")
     */
    public $expiry;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @FormAppend(auto_render=true, form_theme="EntityForm/Form/product_info.twig")
     */
    public $storage;
}
