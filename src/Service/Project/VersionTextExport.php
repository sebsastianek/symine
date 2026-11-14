<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Issue;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The text export of a version (versions/show.text.erb): its name, date and description followed by the list
 * of the visible issues it targets, downloaded as "<name>.txt".
 */
final class VersionTextExport
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    public function response(Version $version): Response
    {
        $cond = $this->issues->visibleCondition($this->currentUser->get(), 'i');
        $issues = $this->em->createQuery("SELECT i FROM App\Entity\Issue i WHERE i.fixedVersion = :v AND $cond ORDER BY i.id")
            ->setParameter('v', $version->id)->getResult();
        $lines = '';
        foreach ($issues as $issue) {
            /* @var Issue $issue */
            $lines .= '* '.$issue->tracker->name.' #'.$issue->id.': '.$issue->subject."\n";
        }
        $parts = array_filter(['# '.$version->name, $this->i18n->formatDate($version->effectiveDate), $version->description, $lines], static fn ($p) => null !== $p);
        $response = new Response(implode("\n\n", $parts), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.addcslashes((string) $version->name, '"\\').'.txt"');

        return $response;
    }
}
