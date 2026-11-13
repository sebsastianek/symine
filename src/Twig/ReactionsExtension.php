<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ReactionService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Port of ReactionsHelper (reaction_button, reaction_id_for). */
final class ReactionsExtension extends AbstractExtension
{
    /** Maximum number of users to display in the reaction button tooltip */
    public const int DISPLAY_REACTION_USERS_LIMIT = 10;

    public function __construct(
        private readonly ReactionService $reactions,
        private readonly UrlGeneratorInterface $urls,
        private readonly AppExtension $app,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('reaction_button', $this->reactionButton(...), ['is_safe' => ['html']]),
            new TwigFunction('reaction_id_for', $this->reactionIdFor(...)),
            new TwigFunction('preload_reactions', $this->preload(...)),
        ];
    }

    /** @param iterable<object> $objects */
    public function preload(iterable $objects): string
    {
        $list = \is_array($objects) ? array_values($objects) : iterator_to_array($objects, false);
        $this->reactions->preload($list);

        return '';
    }

    /** dom_id(object, :reaction) */
    public function reactionIdFor(object $object): string
    {
        return 'reaction_'.strtolower((string) ReactionService::typeOf($object)).'_'.$object->id;
    }

    public function reactionButton(?object $object): string
    {
        if (null === $object || !$this->reactions->isVisible($object)) {
            return '';
        }
        $detail = $this->reactions->detail($object);
        $count = \count($detail['users']);
        $names = array_map(static fn ($u) => $u->getName(), \array_slice($detail['users'], 0, self::DISPLAY_REACTION_USERS_LIMIT));
        $tooltip = $this->reactions->tooltip($names, $count);
        $label = $count > 0 ? (string) $count : null;
        $title = null === $tooltip ? '' : ' title="'.htmlspecialchars($tooltip).'"';
        $type = (string) ReactionService::typeOf($object);
        $query = ['object_id' => $object->id, 'object_type' => $type];
        $has = $count > 0 ? ' has-reactions' : '';

        if ($this->reactions->isEditable($object)) {
            if (null !== ($reaction = $detail['user_reaction'])) {
                $inner = '<a class="icon reaction-button reacted'.$has.'"'.$title.' data-remote="true" rel="nofollow" data-method="delete" href="'
                    .htmlspecialchars($this->urls->generate('reaction_destroy', ['id' => $reaction->id] + $query)).'">'
                    .$this->app->spriteIcon('thumb-up-filled', $label, 18, false, null, true).'</a>';
            } else {
                $inner = '<a class="icon reaction-button'.$has.'"'.$title.' data-remote="true" rel="nofollow" data-method="post" href="'
                    .htmlspecialchars($this->urls->generate('reaction_create', $query)).'">'
                    .$this->app->spriteIcon('thumb-up', $label).'</a>';
            }
        } else {
            $inner = '<span class="icon reaction-button readonly'.$has.'"'.$title.'>'.$this->app->spriteIcon('thumb-up', $label).'</span>';
        }

        return '<span class="reaction-button-wrapper" data-reaction-button-id="'.$this->reactionIdFor($object).'">'.$inner.'</span>';
    }
}
