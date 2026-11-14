<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Service\Settings;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Applies the "user_format" setting to User::getName() (Redmine: User#name uses Setting.user_format),
 * so that every name displayed without an explicit format - select options, filter values, CSV exports,
 * REST API, e-mails - follows the format chosen in Administration » Settings » Display.
 */
final class UserNameFormatListener implements ResetInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 4096)]
    public function apply(): void
    {
        try {
            $format = $this->settings->string('user_format');
        } catch (\Throwable) {
            // database not reachable (yet): keep the current format
            return;
        }
        User::$defaultNameFormat = isset(User::USER_FORMATS[$format]) ? $format : 'firstname_lastname';
    }

    /** The format is process-wide state: back to the default when the kernel is reset */
    public function reset(): void
    {
        User::$defaultNameFormat = 'firstname_lastname';
    }
}
