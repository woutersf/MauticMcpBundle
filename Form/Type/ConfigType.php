<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Form\Type;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class ConfigType extends AbstractType
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('mcp_enabled', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.enabled',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.enabled.tooltip'],
            'data'       => $options['data']['mcp_enabled'] ?? true,
            'required'   => false,
        ]);

        $builder->add('mcp_allow_write', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.allow_write',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.allow_write.tooltip'],
            'data'       => $options['data']['mcp_allow_write'] ?? false,
            'required'   => false,
        ]);

        $builder->add('mcp_allow_delete', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.allow_delete',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.allow_delete.tooltip'],
            'data'       => $options['data']['mcp_allow_delete'] ?? false,
            'required'   => false,
        ]);

        $builder->add('mcp_allow_passthrough', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.allow_passthrough',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.allow_passthrough.tooltip'],
            'data'       => $options['data']['mcp_allow_passthrough'] ?? false,
            'required'   => false,
        ]);

        $builder->add('mcp_allow_oauth', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.allow_oauth',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.allow_oauth.tooltip'],
            'data'       => $options['data']['mcp_allow_oauth'] ?? true,
            'required'   => false,
        ]);

        $builder->add('mcp_allow_basic_auth', YesNoButtonGroupType::class, [
            'label'      => 'plugin.mcp.config.allow_basic_auth',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'plugin.mcp.config.allow_basic_auth.tooltip'],
            'data'       => $options['data']['mcp_allow_basic_auth'] ?? false,
            'required'   => false,
        ]);

        $builder->add('mcp_static_token', TextType::class, [
            'label'      => 'plugin.mcp.config.static_token',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'plugin.mcp.config.static_token.tooltip',
                'placeholder' => 'Leave empty to disable the fixed token',
                'autocomplete'=> 'off',
            ],
            'required' => false,
        ]);

        $builder->add('mcp_static_token_user_id', ChoiceType::class, [
            'label'       => 'plugin.mcp.config.static_token_user',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'tooltip' => 'plugin.mcp.config.static_token_user.tooltip'],
            'choices'     => $this->userChoices(),
            'required'    => false,
            'placeholder' => 'plugin.mcp.config.static_token_user.none',
        ]);

        $builder->add('mcp_access_token_lifetime', IntegerType::class, [
            'label'      => 'plugin.mcp.config.access_token_lifetime',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'tooltip' => 'plugin.mcp.config.access_token_lifetime.tooltip'],
            'required'   => false,
        ]);

        $builder->add('mcp_refresh_token_lifetime', IntegerType::class, [
            'label'      => 'plugin.mcp.config.refresh_token_lifetime',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'tooltip' => 'plugin.mcp.config.refresh_token_lifetime.tooltip'],
            'required'   => false,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function userChoices(): array
    {
        $choices = [];

        try {
            $users = $this->entityManager->getRepository(User::class)->findBy([], ['username' => 'ASC']);
        } catch (\Throwable) {
            return $choices;
        }

        foreach ($users as $user) {
            if (!$user instanceof User || !$user->isPublished()) {
                continue;
            }

            $label           = sprintf('%s (%s)', $user->getName(), $user->getUserIdentifier());
            $choices[$label] = (int) $user->getId();
        }

        return $choices;
    }

    public function getBlockPrefix(): string
    {
        return 'mcpbundleconfig';
    }
}
