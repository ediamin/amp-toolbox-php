<?php

/**
 * DO NOT EDIT!
 * This file was automatically generated via bin/generate-validator-spec.php.
 */

namespace AmpProject\Validator\Spec\AttributeList;

use AmpProject\Attribute;
use AmpProject\Validator\Spec\AttributeList;
use AmpProject\Validator\Spec\SpecRule;

final class AmphtmlModuleEngineAttrs extends AttributeList
{
    /**
     * ID of the attribute list.
     *
     * @var string
     */
    const ID = 'amphtml-module-engine-attrs';

    /**
     * Array of attributes.
     *
     * @var array<array>
     */
    const ATTRIBUTES = [
        Attribute::ASYNC => [
            SpecRule::MANDATORY => true,
            SpecRule::VALUE => [
                '',
            ],
        ],
        Attribute::CROSSORIGIN => [
            SpecRule::MANDATORY => true,
            SpecRule::VALUE => [
                'anonymous',
            ],
        ],
        Attribute::TYPE => [
            SpecRule::MANDATORY => true,
            SpecRule::DISPATCH_KEY => 'NAME_VALUE_DISPATCH', // @todo This value could be put in an interface.
            SpecRule::VALUE_CASEI => [
                'module',
            ],
        ],
    ];
}
