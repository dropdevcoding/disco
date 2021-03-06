<?php

/*
 * This file is part of the Disco package.
 *
 * (c) bitExpert AG
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace bitExpert\Disco\Config;

use bitExpert\Disco\Annotations\Bean;
use bitExpert\Disco\Annotations\BeanPostProcessor;
use bitExpert\Disco\Annotations\Configuration;
use bitExpert\Disco\Annotations\Parameter;
use bitExpert\Disco\Annotations\Parameters;
use bitExpert\Disco\Helper\ParameterizedSampleServiceBeanPostProcessor;
use bitExpert\Disco\Helper\SampleService;

/**
 * @Configuration
 */
class BeanConfigurationWithParameterizedPostProcessor
{
    /**
     * @BeanPostProcessor
     */
    public function sampleServiceBeanPostProcessor() : ParameterizedSampleServiceBeanPostProcessor
    {
        return new ParameterizedSampleServiceBeanPostProcessor($this->dependency());
    }

    /**
     * @Bean
     */
    public function nonSingletonNonLazyRequestBean() : SampleService
    {
        return new SampleService();
    }

    /**
     * @Bean
     * @Parameters({
     *  @Parameter({"name" = "test"})
     * })
     */
    public function dependency($test = '') : \stdClass
    {
        $object = new \stdClass();
        $object->property = $test;
        return $object;
    }
}
