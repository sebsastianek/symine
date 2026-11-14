<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Tracking\Reports\ReportAggregator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** ReportsHelper#aggregate for the issue summary report templates */
final class ReportsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('aggregate', ReportAggregator::aggregate(...))];
    }
}
