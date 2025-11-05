<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Enter project name',
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => 'Enter project description',
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
            ->add('identifier', TextType::class, [
                'label' => 'Identifier',
                'required' => false,
                'help' => 'Lowercase letters (a-z), numbers, dashes and underscores only. Once saved, the identifier cannot be changed.',
                'attr' => [
                    'placeholder' => 'project-identifier',
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                    'pattern' => '[a-z0-9\-_]+',
                ],
            ])
            ->add('homepage', UrlType::class, [
                'label' => 'Homepage',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://example.com',
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
            ->add('isPublic', CheckboxType::class, [
                'label' => 'Public',
                'required' => false,
                'help' => 'If checked, this project will be visible to all users',
                'attr' => [
                    'class' => 'h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded',
                ],
            ])
            ->add('parent', EntityType::class, [
                'class' => Project::class,
                'choice_label' => 'name',
                'label' => 'Subproject of',
                'required' => false,
                'placeholder' => '--- None (top-level project) ---',
                'attr' => [
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
            ->add('inheritMembers', CheckboxType::class, [
                'label' => 'Inherit members',
                'required' => false,
                'help' => 'If checked, members of the parent project will automatically have access to this project',
                'attr' => [
                    'class' => 'h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Active' => 1,
                    'Closed' => 5,
                    'Archived' => 9,
                ],
                'attr' => [
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $OptionsResolver): void
    {
        $OptionsResolver->setDefaults([
            'data_class' => Project::class,
        ]);
    }
}
