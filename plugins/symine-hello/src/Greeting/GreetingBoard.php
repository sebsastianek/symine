<?php

declare(strict_types=1);

namespace Symine\Hello\Greeting;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Plugin\Hook\Hooks;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symine\Hello\Entity\Greeting;
use Symine\Hello\HelloPlugin;

/**
 * The greetings of a project: posting one (form field greeting[message]) and what the "Greetings" tab shows.
 * Controllers stay thin in Symine: this is where the logic of the example plugin lives.
 */
final class GreetingBoard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly Hooks $hooks,
    ) {
    }

    /**
     * Posts the submitted greeting.
     *
     * @return list<string> what must be corrected; empty when the greeting was saved
     */
    public function post(Project $project, User $author, Request $request): array
    {
        $greeting = new Greeting();
        $greeting->project = $project;
        $greeting->author = $author;
        $greeting->message = mb_substr(trim((string) ($request->request->all('greeting')['message'] ?? '')), 0, 255);
        if ('' === $greeting->message) {
            return [$this->i18n->l('field_hello_message').' '.$this->i18n->l('activerecord.errors.messages.blank')];
        }
        // plugins can define their own hooks too
        $this->hooks->call('controller_hello_create_before_save', ['greeting' => $greeting, 'project' => $project]);
        $this->em->persist($greeting);
        $this->em->flush();

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed> the variables of the "Greetings" tab
     */
    public function view(Project $project, array $errors = []): array
    {
        $settings = $this->settings->hash('plugin_'.HelloPlugin::ID);

        return [
            'project' => $project,
            'greetings' => $this->em->createQuery('SELECT g, a FROM '.Greeting::class.' g JOIN g.author a WHERE g.project = :p ORDER BY g.createdOn DESC, g.id DESC')
                ->setParameter('p', $project)->getResult(),
            'errors' => $errors,
            'greeting_word' => (string) ($settings['greeting'] ?? 'Hello'),
        ];
    }
}
