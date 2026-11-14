<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Import;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Gives the import wizard the importer or the import a request designates, once the user is allowed to use it. */
final class ImportFinder
{
    public function __construct(
        private readonly ImporterRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly AccessGuard $guard,
    ) {
    }

    /** ImportsController#authorize_import for a new import: 404 for an unknown type, 403 when not allowed */
    public function importer(?string $type): AbstractImporter
    {
        $importer = $this->registry->get($type);
        if (null === $importer) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$importer->isAuthorized($this->currentUser->get())) {
            $this->guard->deny();
        }

        return $importer;
    }

    /**
     * ImportsController#find_import (+ authorize_import): the import of the current user with that filename.
     * Anonymous visitors are sent to the login page, an unknown import is a 404.
     */
    public function find(string $filename): LoadedImport
    {
        $user = $this->currentUser->get();
        $import = $user->isLogged()
            ? $this->em->getRepository(Import::class)->findOneBy(['user' => $user, 'filename' => $filename])
            : null;
        $importer = null !== $import ? $this->registry->for($import) : null;
        if (null === $import || null === $importer) {
            if (!$user->isLogged()) {
                $this->guard->deny();
            }
            throw new NotFoundHttpException('Not Found');
        }
        if (!$importer->isAuthorized($user)) {
            $this->guard->deny();
        }

        return new LoadedImport($import, $importer);
    }
}
