<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reactions;

use App\Twig\ReactionsExtension;
use Symfony\Component\HttpFoundation\Request;

/**
 * The script answered to the reaction button, which is a remote link: reactions/_replace_button.js.erb.
 */
final class ReactionScript
{
    public function __construct(private readonly ReactionsExtension $helper)
    {
    }

    /** Whether the request asks for a script (format.js): the reactions actions have no other representation */
    public function isRequested(Request $request): bool
    {
        if ('js' === $request->attributes->get('_format') || 'js' === $request->query->get('format')) {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'javascript') || $request->isXmlHttpRequest() && !str_contains($accept, 'html');
    }

    /** reactions/_replace_button.js.erb */
    public function replaceButton(object $object): string
    {
        $id = $this->helper->reactionIdFor($object);
        $button = $this->helper->reactionButton($object);

        return "(() => {\n"
            ."  const button = $('[data-reaction-button-id=".$id."]');\n\n"
            ."  removeHoverTooltips(button);\n"
            .'  button.html($('.json_encode($button, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).").children());\n"
            ."  setupHoverTooltips(button);\n"
            .'})();';
    }
}
