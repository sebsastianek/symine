<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Enumeration;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class IssueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $project = $options['project'];
        $user = $options['user'];
        $isNew = $options['is_new'];

        $builder
            ->add('subject', TextType::class, [
                'label' => 'Subject',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Subject is required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Subject cannot be longer than {{ limit }} characters',
                    ]),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Enter issue subject',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 10,
                    'placeholder' => 'Describe the issue in detail...',
                ],
            ])
            ->add('tracker', EntityType::class, [
                'class' => Tracker::class,
                'choice_label' => 'name',
                'label' => 'Tracker',
                'required' => true,
                'placeholder' => '-- Select Tracker --',
                'query_builder' => function (EntityRepository $er) use ($project) {
                    $qb = $er->createQueryBuilder('t')
                        ->orderBy('t.position', 'ASC');

                    // Filter by project trackers if project is set
                    if ($project) {
                        $qb->innerJoin('App\Entity\ProjectsTracker', 'pt', 'WITH', 'pt.tracker = t')
                           ->where('pt.project = :project')
                           ->setParameter('project', $project);
                    }

                    return $qb;
                },
                'constraints' => [
                    new Assert\NotNull(['message' => 'Tracker is required']),
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('status', EntityType::class, [
                'class' => IssueStatus::class,
                'choice_label' => 'name',
                'label' => 'Status',
                'required' => true,
                'placeholder' => '-- Select Status --',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('s')
                        ->orderBy('s.position', 'ASC');
                },
                'constraints' => [
                    new Assert\NotNull(['message' => 'Status is required']),
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('priority', EntityType::class, [
                'class' => Enumeration::class,
                'choice_label' => 'name',
                'label' => 'Priority',
                'required' => true,
                'placeholder' => '-- Select Priority --',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('e')
                        ->where('e.type = :type')
                        ->setParameter('type', 'IssuePriority')
                        ->andWhere('e.active = :active')
                        ->setParameter('active', true)
                        ->orderBy('e.position', 'ASC');
                },
                'constraints' => [
                    new Assert\NotNull(['message' => 'Priority is required']),
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('assignedTo', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstname() . ' ' . $user->getLastname() . ' (' . $user->getLogin() . ')';
                },
                'label' => 'Assigned To',
                'required' => false,
                'placeholder' => '-- Unassigned --',
                'query_builder' => function (EntityRepository $er) use ($project) {
                    $qb = $er->createQueryBuilder('u')
                        ->where('u.status = :status')
                        ->setParameter('status', User::STATUS_ACTIVE)
                        ->orderBy('u.firstname', 'ASC');

                    // Filter by project members if project is set
                    if ($project) {
                        $qb->innerJoin('App\Entity\Member', 'm', 'WITH', 'm.user = u')
                           ->andWhere('m.project = :project')
                           ->setParameter('project', $project);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('category', EntityType::class, [
                'class' => IssueCategory::class,
                'choice_label' => 'name',
                'label' => 'Category',
                'required' => false,
                'placeholder' => '-- None --',
                'query_builder' => function (EntityRepository $er) use ($project) {
                    $qb = $er->createQueryBuilder('c')
                        ->orderBy('c.name', 'ASC');

                    if ($project) {
                        $qb->where('c.project = :project')
                           ->setParameter('project', $project);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('fixedVersion', EntityType::class, [
                'class' => Version::class,
                'choice_label' => 'name',
                'label' => 'Target Version',
                'required' => false,
                'placeholder' => '-- None --',
                'query_builder' => function (EntityRepository $er) use ($project) {
                    $qb = $er->createQueryBuilder('v')
                        ->orderBy('v.name', 'ASC');

                    if ($project) {
                        $qb->where('v.project = :project')
                           ->setParameter('project', $project);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Start Date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Due Date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('estimatedHours', NumberType::class, [
                'label' => 'Estimated Hours',
                'required' => false,
                'scale' => 2,
                'constraints' => [
                    new Assert\PositiveOrZero(['message' => 'Estimated hours must be positive']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '0.00',
                    'step' => '0.25',
                ],
            ])
            ->add('doneRatio', IntegerType::class, [
                'label' => '% Done',
                'required' => false,
                'data' => 0,
                'constraints' => [
                    new Assert\Range([
                        'min' => 0,
                        'max' => 100,
                        'notInRangeMessage' => 'Done ratio must be between {{ min }}% and {{ max }}%',
                    ]),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                    'step' => 10,
                ],
            ])
            ->add('isPrivate', CheckboxType::class, [
                'label' => 'Private',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label'],
            ])
        ;

        // Add parent issue field for subtasks (only if editing existing issue)
        if (!$isNew) {
            $builder->add('parent', EntityType::class, [
                'class' => Issue::class,
                'choice_label' => function (Issue $issue) {
                    return '#' . $issue->getId() . ': ' . $issue->getSubject();
                },
                'label' => 'Parent Issue',
                'required' => false,
                'placeholder' => '-- None --',
                'query_builder' => function (EntityRepository $er) use ($project) {
                    $qb = $er->createQueryBuilder('i')
                        ->orderBy('i.id', 'DESC');

                    if ($project) {
                        $qb->where('i.project = :project')
                           ->setParameter('project', $project);
                    }

                    return $qb;
                },
                'attr' => ['class' => 'form-select'],
            ]);
        }

        // Set project automatically if provided
        if ($project) {
            $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($project) {
                $issue = $event->getData();
                if ($issue && !$issue->getId()) {
                    $issue->setProject($project);
                }
            });
        }

        // Set author automatically for new issues
        if ($isNew && $user) {
            $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($user) {
                $issue = $event->getData();
                if ($issue && !$issue->getId()) {
                    $issue->setAuthor($user);
                }
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Issue::class,
            'project' => null,
            'user' => null,
            'is_new' => true,
        ]);

        $resolver->setAllowedTypes('project', ['null', Project::class]);
        $resolver->setAllowedTypes('user', ['null', User::class]);
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
