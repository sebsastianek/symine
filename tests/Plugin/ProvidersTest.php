<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Entity\CustomField;
use App\Entity\IssueCustomField;
use App\Plugin\Activity\ActivityProviderRegistry;
use App\Plugin\FieldFormat\FieldFormatRegistry;
use App\Plugin\Search\SearchProviderRegistry;
use App\Service\Activity\ActivityFetcher;
use App\Service\Activity\SearchService;
use App\Service\Admin\FieldFormats;
use App\Service\CustomFieldService;

/** Activity providers, search providers and custom field formats registered by the example plugin */
final class ProvidersTest extends PluginWebTestCase
{
    public function testActivityProviderRegistration(): void
    {
        self::assertNotNull(self::getContainer()->get(ActivityProviderRegistry::class)->get('hello_greetings'));
        $fetcher = self::getContainer()->get(ActivityFetcher::class);
        self::assertSame([false, 'view_hello_greetings'], $fetcher->availableEventTypes()['hello_greetings']);
        self::assertNotContains('hello_greetings', $fetcher->defaultTypes(), 'default: false');
        self::assertSame(ActivityFetcher::defaultEventTypes(), $fetcher->defaultTypes());
        self::assertContains('hello_greetings', $fetcher->eventTypes($this->user('jsmith'), null));
        self::assertNotContains('hello_greetings', $fetcher->eventTypes($this->user('jsmith'), $this->project()), 'module disabled');
        $this->enableHello();
        self::getContainer()->get(\App\Security\Authorization::class)->reset();
        self::assertContains('hello_greetings', $fetcher->eventTypes($this->user('jsmith'), $this->project()));
    }

    public function testActivityEvents(): void
    {
        $project = $this->enableHello();
        $g = $this->addGreeting($project, 'Activity greeting');
        $fetcher = self::getContainer()->get(ActivityFetcher::class);
        $events = $fetcher->events($this->user('jsmith'), ['hello_greetings'], $project, true);
        self::assertCount(1, $events);
        self::assertSame('hello-greeting', $events[0]->type);
        self::assertSame('Greeting #'.$g->id.': Activity greeting', $events[0]->title);
        self::assertSame('jsmith', $events[0]->author->login);
        self::assertSame([], $fetcher->events($this->user('dlopper'), ['hello_greetings'], $project, true), 'no permission');
        self::assertSame([], $fetcher->events($this->user('jsmith'), ['hello_greetings'], $project, true, $this->user('admin')), 'author filter');
        $mixed = $fetcher->events($this->user('jsmith'), ['issues', 'hello_greetings'], null, true, null, null, null, 200);
        self::assertCount(1, array_filter($mixed, static fn ($x) => 'hello-greeting' === $x->type), 'merged with core events');

        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce/activity');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('#sidebar input[name="show_hello_greetings"]'));
        self::assertStringContainsString('Greetings', $crawler->filter('#sidebar')->text());
        self::assertStringNotContainsString('Activity greeting', $crawler->filter('#content')->text(), 'not in the default scope');
        $crawler = $this->get('/projects/ecommerce/activity?show_hello_greetings=1');
        self::assertStringContainsString('Greeting #'.$g->id.': Activity greeting', $crawler->filter('#content')->text());
        self::assertCount(1, $crawler->filter('#content dt.hello-greeting'));
        $this->get('/projects/ecommerce/activity.atom?show_hello_greetings=1');
        self::assertStringContainsString('Activity greeting', $this->body());
    }

    public function testSearchProvider(): void
    {
        $project = $this->enableHello();
        $g = $this->addGreeting($project, 'Searchable salutation');
        self::assertNotNull(self::getContainer()->get(SearchProviderRegistry::class)->get('hello_greetings'));
        $search = self::getContainer()->get(SearchService::class);
        self::assertSame([...SearchService::TYPES, 'hello_greetings'], $search->types());
        $ids = $search->resultIds(['salutation'], $this->user('jsmith'), ['hello_greetings', 'issues'], null);
        self::assertSame([['hello_greetings', $g->id]], $ids);
        self::assertSame([], $search->resultIds(['salutation'], $this->user('dlopper'), ['hello_greetings'], null));
        self::assertSame([], $search->resultIds(['salutation'], $this->user('jsmith'), ['hello_greetings'], [$this->project('internal')]));
        $results = $search->results($ids);
        self::assertSame('Greeting #'.$g->id.': Searchable salutation', $results[0]->title);

        $this->loginAs('jsmith');
        $crawler = $this->get('/search?q=salutation');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('input#hello_greetings'));
        self::assertStringContainsString('Greetings', $crawler->filter('p#search-types')->text());
        self::assertStringContainsString('Searchable salutation', $crawler->filter('#search-results')->text());
        $crawler = $this->get('/projects/ecommerce/search?q=salutation&hello_greetings=1');
        $this->assertStatus(200);
        self::assertStringContainsString('Searchable salutation', $crawler->filter('#search-results')->text());
        $this->client->request('GET', '/search.json?q=salutation', [], [], ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'admin']);
        self::assertSame('hello-greeting', json_decode($this->body(), true)['results'][0]['type'] ?? null);
    }

    public function testFieldFormat(): void
    {
        self::assertNotNull(self::getContainer()->get(FieldFormatRegistry::class)->get('hello_color'));
        $formats = self::getContainer()->get(FieldFormats::class);
        self::assertSame('label_hello_color', $formats->info('hello_color')['label']);
        self::assertContains(['Color (Hello plugin)', 'hello_color'], $formats->formatsForSelect('IssueCustomField'));
        $field = new IssueCustomField();
        $formats->assign($field, ['field_format' => 'hello_color', 'name' => 'Color']);
        self::assertSame('hello_color', $field->fieldFormat);

        $cfs = self::getContainer()->get(CustomFieldService::class);
        self::assertSame([], $cfs->validate($field, '#A0b1C2'));
        self::assertSame(['Color is invalid'], $cfs->validate($field, 'red'));
        self::assertSame('#a0b1c2', $cfs->formatValue($field, '#A0B1C2'));

        $this->loginAs('admin');
        $crawler = $this->get('/custom_fields/new?type=IssueCustomField');
        self::assertCount(1, $crawler->filter('select#custom_field_field_format option[value="hello_color"]'));
        $this->post('/custom_fields', ['type' => 'IssueCustomField', 'custom_field' => ['field_format' => 'hello_color', 'name' => 'Plugin color', 'is_for_all' => '1', 'tracker_ids' => ['1']]]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());
        $cf = $this->em->getRepository(CustomField::class)->findOneBy(['name' => 'Plugin color']);
        self::assertSame('hello_color', $cf->fieldFormat);
        $crawler = $this->get('/custom_fields?tab=IssueCustomField');
        self::assertStringContainsString('Color (Hello plugin)', $crawler->filter('table')->text());
    }
}
