<?php

declare(strict_types=1);

namespace Symine\Hello;

use App\Plugin\AbstractSyminePlugin;
use App\Plugin\PluginDefinition;

/**
 * Example plugin. The Redmine equivalent would be:
 *
 *     Redmine::Plugin.register :symine_hello do
 *       name 'Symine Hello'
 *       author 'Symine'
 *       description '...'
 *       version '1.0.0'
 *       requires_redmine version_or_higher: '7.0'
 *       settings default: {'greeting' => 'Hello', 'show_on_issues' => '1'}, partial: 'settings/hello_settings'
 *       project_module :hello do
 *         permission :view_hello_greetings, {hello: [:index]}, read: true
 *         permission :add_hello_greetings, {hello: [:create]}, require: :loggedin
 *       end
 *       menu :project_menu, :hello, {controller: 'hello', action: 'index'}, caption: :label_hello_greeting_plural,
 *            after: :activity, param: :project_id
 *       activity_provider :hello_greetings, class_name: 'HelloGreeting', default: false
 *     end
 */
final class HelloPlugin extends AbstractSyminePlugin
{
    public const string ID = 'symine_hello';

    public function getPluginId(): string
    {
        return self::ID;
    }

    public function register(PluginDefinition $plugin): void
    {
        $plugin
            ->name('Symine Hello')
            ->author('Symine')
            ->authorUrl('https://github.com/symine/symine')
            ->description('Example plugin: greetings project module')
            ->url('https://github.com/symine/symine/tree/main/plugins/symine-hello')
            ->version('1.0.0')
            ->requiresSymine('0.1')
            ->requiresRedmine(['version_or_higher' => '7.0'])
            ->settings(['greeting' => 'Hello', 'show_on_issues' => '1'], '@HelloPlugin/settings/_hello_settings.html.twig')
            ->projectModule('hello', static function (PluginDefinition $p): void {
                $p->permission('view_hello_greetings', ['hello_index'], ['read' => true]);
                $p->permission('add_hello_greetings', ['hello_create'], ['require' => 'loggedin']);
            })
            ->menu('project_menu', 'hello', 'hello_index', [
                'caption' => 'label_hello_greeting_plural',
                'param' => 'project_id',
                'after' => 'activity',
            ]);
    }
}
