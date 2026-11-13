<?php

declare(strict_types=1);

namespace App\Plugin;

/** Redmine::PluginRequirementError: raised when requires_symine / requires_symine_plugin are not satisfied */
final class PluginRequirementException extends \RuntimeException
{
}
