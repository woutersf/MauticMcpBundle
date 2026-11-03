<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use MauticPlugin\MauticMcpBundle\Form\Type\ConfigType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $event->addForm([
            'bundle'     => 'MauticMcpBundle',
            'formType'   => ConfigType::class,
            'formAlias'  => 'mcpbundleconfig',
            'formTheme'  => '@MauticMcp/FormTheme/Config/_config_mcpbundle_widget.html.twig',
            'parameters' => $event->getParametersFromConfig('MauticMcpBundle'),
        ]);
    }
}
