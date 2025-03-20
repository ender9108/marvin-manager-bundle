<?php

namespace EnderLab\MarvinManagerBundle;

use EnderLab\MarvinManagerBundle\Messenger\Attribute\AsMessageType;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MarvinManagerBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        $builder->registerAttributeForAutoconfiguration(AsMessageType::class, static function (
            ChildDefinition      $definition,
            AsMessageType        $attribute,
            ReflectionClass     $reflector
        ): void {
            $definition->addTag('marvin.message.handler', ['binding' => $attribute->binding]);
        });
    }
}
