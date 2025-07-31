<?php

namespace App\Form;

use App\Entity\Project;
use App\Entity\IssueStatuse;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Enumeration;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IssueFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('search', TextType::class, [
                'label' => 'Search',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Search issues...',
                    'class' => 'form-control',
                ],
            ])
            ->add('project', EntityType::class, [
                'class' => Project::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'All Projects',
                'query_builder' => function (EntityRepository $er) use ($options) {
                    $qb = $er->createQueryBuilder('p')
                        ->orderBy('p.name', 'ASC');
                    
                    if (!$options['is_admin']) {
                        $qb->leftJoin('App\Entity\Member', 'm', 'WITH', 'm.project = p AND m.user = :user')
                           ->where('p.isPublic = 1 OR m.id IS NOT NULL')
                           ->setParameter('user', $options['user']);
                    }
                    
                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('status', EntityType::class, [
                'class' => IssueStatuse::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'All Statuses',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('s')
                        ->orderBy('s.position', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('tracker', EntityType::class, [
                'class' => Tracker::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'All Trackers',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('t')
                        ->orderBy('t.position', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('priority', EntityType::class, [
                'class' => Enumeration::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'All Priorities',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('e')
                        ->where('e.type = :type')
                        ->setParameter('type', 'IssuePriority')
                        ->orderBy('e.position', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('assignedTo', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstname() . ' ' . $user->getLastname();
                },
                'required' => false,
                'placeholder' => 'All Assignees',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('u')
                        ->where('u.status = :status')
                        ->setParameter('status', User::STATUS_ACTIVE)
                        ->orderBy('u.firstname', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('showClosed', ChoiceType::class, [
                'choices' => [
                    'Open issues only' => 'open',
                    'Closed issues only' => 'closed',
                    'All issues' => 'all',
                ],
                'data' => 'open',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('dateFrom', DateType::class, [
                'label' => 'Created from',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('dateTo', DateType::class, [
                'label' => 'Created to',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('filter', SubmitType::class, [
                'label' => 'Filter',
                'attr' => ['class' => 'btn btn-primary'],
            ])
            ->add('reset', SubmitType::class, [
                'label' => 'Reset',
                'attr' => ['class' => 'btn btn-secondary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
            'user' => null,
            'is_admin' => false,
        ]);
    }
}