<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Entity\Issue;
use App\Plugin\Hook\HookEvent;
use App\Plugin\Hook\HookListenerInterface;
use App\Plugin\Hook\Hooks;
use App\Plugin\Hook\ViewListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** View hooks (Twig call_hook) and controller/model hooks (HookEvent) */
final class HooksTest extends PluginWebTestCase
{
    private function issueOf(string $project = 'ecommerce'): Issue
    {
        $issue = $this->em->getRepository(Issue::class)->findOneBy(['project' => $this->project($project)], ['id' => 'ASC']);
        self::assertNotNull($issue);

        return $issue;
    }

    public function testIssuePageHook(): void
    {
        $project = $this->enableHello();
        $this->addGreeting($project, 'Hi');
        $issue = $this->issueOf();
        $this->loginAs('jsmith');
        $crawler = $this->get('/issues/'.$issue->id);
        $this->assertStatus(200);
        $box = $crawler->filter('div.issue div.hello-issue-greeting');
        self::assertCount(1, $box, 'rendered inside the issue details');
        self::assertStringContainsString('Hello! This project has 1 greeting.', $box->text());
        // view_layouts_base_html_head
        self::assertCount(1, $crawler->filter('head link[href="/plugin_assets/symine_hello/stylesheets/hello.css"]'));

        // the plugin setting changes the hook output; show_on_issues=0 disables it
        $settings = self::getContainer()->get(\App\Service\Settings::class);
        $settings->set('plugin_symine_hello', ['greeting' => 'Ahoj', 'show_on_issues' => '1']);
        $this->em->flush();
        self::assertStringContainsString('Ahoj!', $this->get('/issues/'.$issue->id)->filter('div.hello-issue-greeting')->text());
        $settings->set('plugin_symine_hello', ['greeting' => 'Ahoj', 'show_on_issues' => '0']);
        $this->em->flush();
        self::assertCount(0, $this->get('/issues/'.$issue->id)->filter('div.hello-issue-greeting'));
    }

    public function testIssuePageHookNeedsPermission(): void
    {
        $this->enableHello();
        $this->loginAs('dlopper');
        $crawler = $this->get('/issues/'.$this->issueOf()->id);
        $this->assertStatus(200);
        self::assertCount(0, $crawler->filter('div.hello-issue-greeting'));
    }

    public function testRenderOnProjectOverview(): void
    {
        $project = $this->enableHello();
        $this->addGreeting($project, 'Welcome to eCommerce');
        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce');
        self::assertStringContainsString('Welcome to eCommerce', $crawler->filter('.splitcontentright div.hello.box')->text());
    }

    public function testListenerMethodsRenderOnAndEvents(): void
    {
        $hooks = self::getContainer()->get(Hooks::class);
        $hooks->addListener(new class implements HookListenerInterface {
            public function view_welcome_index_left(array $context): string
            {
                return '<p id="method-hook">method '.($context['hook_caller'] ?? '').'</p>';
            }
        });
        $view = new class extends ViewListener {
            protected const array RENDER_ON = ['view_layouts_base_body_bottom' => 'admin/settings/_test_hook.html.twig'];
        };
        self::assertTrue($view->rendersOn('view_layouts_base_body_bottom'));
        self::assertFalse($view->rendersOn('view_welcome_index_left'));
        self::getContainer()->get(EventDispatcherInterface::class)->addListener('view_welcome_index_right', static function (HookEvent $e): void {
            $e->addResponse('<p id="event-hook">event</p>');
        });
        self::getContainer()->get(EventDispatcherInterface::class)->addListener('view_layouts_base_sidebar', static function (HookEvent $e): void {
            $e->addResponse('<p id="sidebar-hook">sidebar</p>');
        });
        self::assertTrue($hooks->hasListeners('view_welcome_index_left'));
        self::assertFalse($hooks->hasListeners('view_nothing'));
        self::assertSame('', $hooks->render('view_nothing'));

        $crawler = $this->get('/');
        $this->assertStatus(200);
        self::assertSame('method view', $crawler->filter('.splitcontentleft #method-hook')->text());
        self::assertCount(1, $crawler->filter('.splitcontentright #event-hook'));
        self::assertCount(1, $crawler->filter('#sidebar-wrapper #sidebar-hook'));
        self::assertCount(1, $crawler->filter('#main.collapsiblesidebar'), 'a sidebar hook response enables the sidebar');
    }

    public function testControllerHooksAsEvents(): void
    {
        $calls = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        foreach (['controller_issues_edit_before_save', 'controller_issues_edit_after_save', 'controller_issues_new_before_save', 'controller_issues_new_after_save'] as $name) {
            $dispatcher->addListener($name, static function (HookEvent $e) use (&$calls): void {
                $calls[] = [$e->hook, $e->get('issue')?->id, null !== $e['journal'], $e['params']['issue']['notes'] ?? null];
                if ('controller_issues_edit_before_save' === $e->hook) {
                    $e->get('issue')->subject .= ' (hooked)';
                }
            });
        }
        $issue = $this->issueOf();
        $this->loginAs('jsmith');
        $this->post('/issues/'.$issue->id, ['issue' => ['notes' => 'hook test']], 'PATCH');
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([
            ['controller_issues_edit_before_save', $issue->id, false, 'hook test'],
            ['controller_issues_edit_after_save', $issue->id, true, 'hook test'],
        ], $calls);
        $this->em->clear();
        self::assertStringEndsWith(' (hooked)', $this->em->find(Issue::class, $issue->id)->subject, 'before_save listeners can change the issue');

        $calls = [];
        $this->post('/projects/ecommerce/issues', ['issue' => ['tracker_id' => $issue->tracker->id, 'subject' => 'Created with hooks']]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['controller_issues_new_before_save', 'controller_issues_new_after_save'], array_column($calls, 0));
        self::assertNull($calls[0][1]);
        self::assertNotNull($calls[1][1]);
    }

    public function testModelProjectCopyHook(): void
    {
        $seen = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener('model_project_copy_before_save', static function (HookEvent $e) use (&$seen): void {
            $seen[] = [$e->get('source_project')->identifier, $e->get('destination_project')->identifier];
        });
        $manager = self::getContainer()->get(\App\Service\ProjectManager::class);
        $target = $manager->copyFrom($this->project());
        $target->name = 'Copy';
        $target->identifier = 'copy-hook';
        self::getContainer()->get(\App\Security\CurrentUser::class)->set($this->user('admin'));
        self::assertSame([], $manager->copy($this->project(), $target, ['members']));
        self::assertSame([['ecommerce', 'copy-hook']], $seen);
    }

    public function testLoginHook(): void
    {
        $users = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener('controller_account_success_authentication_after', static function (HookEvent $e) use (&$users): void {
            $users[] = $e->get('user')->login;
        });
        $this->user('rhill')->hashedPassword = password_hash('password123', \PASSWORD_BCRYPT, ['cost' => 4]);
        $this->user('rhill')->salt = null;
        $this->em->flush();
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('#login-form form')->form(['username' => 'rhill', 'password' => 'password123']);
        $this->client->submit($form);
        self::assertSame(['rhill'], $users);
    }
}
