<?php

/*
 * This file is part of the Disco package.
 *
 * (c) bitExpert AG
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types = 1);

namespace bitExpert\Disco\Proxy\Configuration;

use bitExpert\Disco\Annotations\Bean;
use bitExpert\Disco\Annotations\BeanPostProcessor;
use bitExpert\Disco\Annotations\Configuration;
use bitExpert\Disco\Annotations\Parameters;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\BeanMethod;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\Constructor;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\GetAlias;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\GetParameter;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\HasAlias;
use bitExpert\Disco\Proxy\Configuration\MethodGenerator\MagicSleep;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\AliasesProperty;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\BeanFactoryConfigurationProperty;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\BeanPostProcessorsProperty;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\ForceLazyInitProperty;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\ParameterValuesProperty;
use bitExpert\Disco\Proxy\Configuration\PropertyGenerator\SessionBeansProperty;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;
use Exception;
use ProxyManager\Exception\InvalidProxiedClassException;
use ProxyManager\ProxyGenerator\Assertion\CanProxyAssertion;
use ProxyManager\ProxyGenerator\ProxyGeneratorInterface;
use ReflectionClass;
use ReflectionMethod;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\Exception\InvalidArgumentException;
use Zend\Code\Reflection\MethodReflection;

/**
 * Generator for configuration classes.
 */
class ConfigurationGenerator implements ProxyGeneratorInterface
{
    /**
     * @var Reader
     */
    protected $reader;

    /**
     * Creates a new {@link \bitExpert\Disco\Proxy\Configuration\ConfigurationGenerator}.
     *
     * @param Cache $cache
     */
    public function __construct(Cache $cache = null)
    {
        // registers all required annotations
        AnnotationRegistry::registerFile(__DIR__ . '/../../Annotations/Bean.php');
        AnnotationRegistry::registerFile(__DIR__ . '/../../Annotations/BeanPostProcessor.php');
        AnnotationRegistry::registerFile(__DIR__ . '/../../Annotations/Configuration.php');
        AnnotationRegistry::registerFile(__DIR__ . '/../../Annotations/Parameters.php');
        AnnotationRegistry::registerFile(__DIR__ . '/../../Annotations/Parameter.php');

        $this->reader = new AnnotationReader();

        if ($cache instanceof Cache) {
            $this->reader = new CachedReader($this->reader, $cache);
        }
    }

    /**
     * {@inheritDoc}
     * @throws InvalidProxiedClassException
     * @throws InvalidArgumentException
     */
    public function generate(ReflectionClass $originalClass, ClassGenerator $classGenerator)
    {
        CanProxyAssertion::assertClassCanBeProxied($originalClass, false);

        $annotation = null;
        $forceLazyInitProperty = new ForceLazyInitProperty();
        $sessionBeansProperty = new SessionBeansProperty();
        $postProcessorsProperty = new BeanPostProcessorsProperty();
        $parameterValuesProperty = new ParameterValuesProperty();
        $beanFactoryConfigurationProperty = new BeanFactoryConfigurationProperty();
        $aliasesProperty = new AliasesProperty();
        $getParameterMethod = new GetParameter($originalClass, $parameterValuesProperty);

        try {
            $annotation = $this->reader->getClassAnnotation($originalClass, Configuration::class);
        } catch (Exception $e) {
            throw new InvalidProxiedClassException($e->getMessage(), $e->getCode(), $e);
        }

        if (null === $annotation) {
            throw new InvalidProxiedClassException(
                sprintf(
                    '"%s" seems not to be a valid configuration class. @Configuration annotation missing!',
                    $originalClass->getName()
                )
            );
        }

        $classGenerator->setExtendedClass($originalClass->getName());
        $classGenerator->setImplementedInterfaces([AliasContainerInterface::class]);
        $classGenerator->addPropertyFromGenerator($forceLazyInitProperty);
        $classGenerator->addPropertyFromGenerator($sessionBeansProperty);
        $classGenerator->addPropertyFromGenerator($postProcessorsProperty);
        $classGenerator->addPropertyFromGenerator($parameterValuesProperty);
        $classGenerator->addPropertyFromGenerator($beanFactoryConfigurationProperty);
        $classGenerator->addPropertyFromGenerator($aliasesProperty);

        $postProcessorMethods = [];
        $aliases = [];
        $methods = $originalClass->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
        foreach ($methods as $method) {
            if (null !== $this->reader->getMethodAnnotation($method, BeanPostProcessor::class)) {
                $postProcessorMethods[] = $method->getName();
                continue;
            }

            /* @var \bitExpert\Disco\Annotations\Bean $beanAnnotation */
            $beanAnnotation = $this->reader->getMethodAnnotation($method, Bean::class);
            if (null === $beanAnnotation) {
                throw new InvalidProxiedClassException(
                    sprintf(
                        'Method "%s" on "%s" is missing the @Bean annotation!',
                        $method->getName(),
                        $originalClass->getName()
                    )
                );
            }

            // if alias is defined append it to the aliases list
            if ($beanAnnotation->getAlias() !== '' && !isset($aliases[$beanAnnotation->getAlias()])) {
                $aliases[$beanAnnotation->getAlias()] = $method->getName();
            }

            /* @var \bitExpert\Disco\Annotations\Parameters $parametersAnnotation */
            $parametersAnnotation = $this->reader->getMethodAnnotation($method, Parameters::class);
            if (null === $parametersAnnotation) {
                $parametersAnnotation = new Parameters();
            }

            $beanType = $method->getReturnType();
            if (null === $beanType) {
                throw new InvalidProxiedClassException(
                    sprintf(
                        'Method "%s" on "%s" is missing the return typehint!',
                        $method->getName(),
                        $originalClass->getName()
                    )
                );
            }

            $beanType = (string) $beanType;
            if (!in_array($beanType, ['array', 'callable', 'bool', 'float', 'int', 'string']) &&
                !class_exists($beanType) &&
                !interface_exists($beanType)
            ) {
                throw new InvalidProxiedClassException(
                    sprintf(
                        'Return type of method "%s" on "%s" cannot be found! Did you use the full qualified name?',
                        $method->getName(),
                        $originalClass->getName()
                    )
                );
            }

            $methodReflection = new MethodReflection(
                $method->getDeclaringClass()->getName(),
                $method->getName()
            );
            $proxyMethod = BeanMethod::generateMethod(
                $methodReflection,
                $beanAnnotation,
                $parametersAnnotation,
                $getParameterMethod,
                $forceLazyInitProperty,
                $sessionBeansProperty,
                $postProcessorsProperty,
                $beanFactoryConfigurationProperty,
                $beanType
            );
            $classGenerator->addMethodFromGenerator($proxyMethod);
        }

        $aliasesProperty->setDefaultValue($aliases);

        $classGenerator->addMethodFromGenerator(
            new Constructor(
                $originalClass,
                $parameterValuesProperty,
                $sessionBeansProperty,
                $beanFactoryConfigurationProperty,
                $postProcessorsProperty,
                $postProcessorMethods
            )
        );
        $classGenerator->addMethodFromGenerator($getParameterMethod);
        $classGenerator->addMethodFromGenerator(
            new MagicSleep(
                $originalClass,
                $sessionBeansProperty
            )
        );
        $classGenerator->addMethodFromGenerator(
            new GetAlias(
                $originalClass,
                $aliasesProperty
            )
        );
        $classGenerator->addMethodFromGenerator(
            new HasAlias(
                $originalClass,
                $aliasesProperty
            )
        );
    }
}
