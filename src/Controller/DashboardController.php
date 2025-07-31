<?php

namespace App\Controller;

use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Repository\MemberRepository;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IssueRepository $issueRepository,
        private ProjectRepository $projectRepository,
        private MemberRepository $memberRepository,
        private TimeEntryRepository $timeEntryRepository
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get stats
        $stats = $this->getDashboardStats($user);
        
        // Get issues assigned to current user
        $assignedIssues = $this->getAssignedIssues($user);
        
        // Get recent activities
        $recentActivities = $this->getRecentActivities($user);
        
        // Get user's projects with stats
        $projects = $this->getUserProjects($user);
        
        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'recentActivities' => $recentActivities,
            'assignedIssues' => $assignedIssues,
            'projects' => $projects,
            'stats' => $stats,
        ]);
    }

    private function getDashboardStats($user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Total issues in projects user has access to
        $totalIssues = $qb
            ->select('COUNT(i.id)')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1')
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getSingleScalarResult();

        // Open issues (not closed status)
        $openIssues = $qb
            ->select('COUNT(i.id)')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->innerJoin('i.issueStatus', 's')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('(p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1)')
            ->andWhere('s.isClosed = 0')
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getSingleScalarResult();

        // Closed issues
        $closedIssues = $qb
            ->select('COUNT(i.id)')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->innerJoin('i.issueStatus', 's')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('(p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1)')
            ->andWhere('s.isClosed = 1')
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getSingleScalarResult();

        // Issues assigned to current user
        $myIssues = $qb
            ->select('COUNT(i.id)')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('(p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1)')
            ->andWhere('i.assignedTo = :user')
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'totalIssues' => $totalIssues,
            'openIssues' => $openIssues,
            'closedIssues' => $closedIssues,
            'myIssues' => $myIssues,
        ];
    }

    private function getAssignedIssues($user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $issues = $qb
            ->select('i', 'p', 's', 'pr', 't')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->innerJoin('i.issueStatus', 's')
            ->innerJoin('i.priority', 'pr')
            ->innerJoin('i.tracker', 't')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('(p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1)')
            ->andWhere('i.assignedTo = :user')
            ->andWhere('s.isClosed = 0')
            ->orderBy('i.updatedOn', 'DESC')
            ->setMaxResults(10)
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getResult();

        return $issues;
    }

    private function getRecentActivities($user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Get recently created issues
        $recentIssues = $qb
            ->select('i', 'p', 'a')
            ->from('App\Entity\Issue', 'i')
            ->innerJoin('i.project', 'p')
            ->innerJoin('i.author', 'a')
            ->leftJoin('p.members', 'm', 'WITH', 'm.user = :user')
            ->where('(p.isPublic = 1 OR m.id IS NOT NULL OR :isAdmin = 1)')
            ->orderBy('i.createdOn', 'DESC')
            ->setMaxResults(10)
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getResult();

        $activities = [];
        foreach ($recentIssues as $issue) {
            $activities[] = [
                'type' => 'issue_created',
                'title' => $issue->getSubject(),
                'project' => $issue->getProject()->getName(),
                'user' => $issue->getAuthor()->getFirstname() . ' ' . $issue->getAuthor()->getLastname(),
                'time' => $this->timeAgo($issue->getCreatedOn()),
                'id' => $issue->getId()
            ];
        }

        return $activities;
    }

    private function getUserProjects($user): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Get projects user has access to with issue counts
        $projects = $qb
            ->select('p', 'COUNT(i.id) as issueCount', 'COUNT(m.id) as memberCount', 'AVG(i.doneRatio) as avgProgress')
            ->from('App\Entity\Project', 'p')
            ->leftJoin('p.issues', 'i')
            ->leftJoin('p.members', 'pm')
            ->leftJoin('pm.user', 'm')
            ->leftJoin('p.members', 'um', 'WITH', 'um.user = :user')
            ->where('p.isPublic = 1 OR um.id IS NOT NULL OR :isAdmin = 1')
            ->groupBy('p.id')
            ->orderBy('p.name', 'ASC')
            ->setParameter('user', $user)
            ->setParameter('isAdmin', $user->getAdmin())
            ->getQuery()
            ->getResult();

        $projectData = [];
        foreach ($projects as $result) {
            $project = $result[0]; // The Project entity
            $projectData[] = [
                'id' => $project->getId(),
                'name' => $project->getName(),
                'description' => $project->getDescription() ?: 'No description available',
                'progress' => round($result['avgProgress'] ?: 0),
                'issues' => $result['issueCount'],
                'members' => $result['memberCount'],
            ];
        }

        return $projectData;
    }

    private function timeAgo(\DateTimeInterface $datetime): string
    {
        $now = new \DateTime();
        $interval = $now->diff($datetime);
        
        if ($interval->d > 0) {
            return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        } elseif ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    }
}