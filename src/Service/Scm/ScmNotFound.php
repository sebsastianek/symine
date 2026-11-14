<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The entry or revision was not found in the repository (RepositoriesController#show_error_not_found):
 * a 404 rendered with the "error_scm_not_found" message instead of the generic "not found" page
 * (see App\Service\Scm\Http\ScmErrorSubscriber).
 */
#[Exclude]
final class ScmNotFound extends NotFoundHttpException
{
    /**
     * @param bool $page true when the error page is shown whatever the format of the request; otherwise an API
     *                   request (json/xml) gets the empty 404 of every other "not found"
     */
    public function __construct(public readonly bool $page = false)
    {
        parent::__construct();
    }
}
