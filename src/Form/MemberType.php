<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Member;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $project = $options['project'];

        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return sprintf('%s %s (%s)', $user->getFirstname(), $user->getLastname(), $user->getLogin());
                },
                'label' => 'User',
                'required' => true,
                'placeholder' => '--- Select a user ---',
                'query_builder' => function (UserRepository $repository) use ($project) {
                    // Get users who are not already members of this project
                    return $repository->createQueryBuilder('u')
                        ->where('u.status = :status')
                        ->andWhere('NOT EXISTS (
                            SELECT m.id FROM App\Entity\Member m
                            WHERE m.user = u AND m.project = :project
                        )')
                        ->setParameter('status', 1) // Active users only
                        ->setParameter('project', $project)
                        ->orderBy('u.lastname', 'ASC')
                        ->addOrderBy('u.firstname', 'ASC');
                },
                'attr' => [
                    'class' => 'w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500',
                ],
            ])
            ->add('roles', EntityType::class, [
                'class' => Role::class,
                'choice_label' => 'name',
                'label' => 'Roles',
                'required' => true,
                'multiple' => true,
                'expanded' => true,
                'query_builder' => function (RoleRepository $repository) {
                    // Get only assignable roles
                    return $repository->createQueryBuilder('r')
                        ->where('r.assignable = :assignable')
                        ->andWhere('r.builtin = :builtin')
                        ->setParameter('assignable', true)
                        ->setParameter('builtin', 0) // Not built-in roles
                        ->orderBy('r.position', 'ASC');
                },
                'attr' => [
                    'class' => 'space-y-2',
                ],
            ])
            ->add('mailNotification', CheckboxType::class, [
                'label' => 'Email notifications',
                'required' => false,
                'help' => 'Receive email notifications for activity on this project',
                'attr' => [
                    'class' => 'h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Member::class,
            'project' => null,
        ]);

        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', 'App\Entity\Project');
    }
}
