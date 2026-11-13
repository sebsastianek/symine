<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Doctrine\RedmineSerializedType;
use App\Redmine\RubyYaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rails/Psych (and legacy Syck) YAML as found in Redmine databases of various ages */
final class RubyYamlTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'HashWithIndifferentAccess' => [
            "--- !ruby/hash:ActiveSupport::HashWithIndifferentAccess\nstatus_id: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n  operator: o\n  values:\n  - ''\n",
            ['status_id' => ['operator' => 'o', 'values' => ['']]],
        ];
        yield 'ActionController::Parameters at document root' => [
            "--- !ruby/object:ActionController::Parameters\nparameters: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n  tracker_id: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n    operator: \"=\"\n    values:\n    - '1'\npermitted: false\n",
            ['tracker_id' => ['operator' => '=', 'values' => ['1']]],
        ];
        yield 'nested ActionController::Parameters' => [
            "---\nstatus_id: !ruby/object:ActionController::Parameters\n  parameters: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n    operator: o\n    values:\n    - ''\n  permitted: true\n",
            ['status_id' => ['operator' => 'o', 'values' => ['']]],
        ];
        yield 'symbol values (column_names)' => ["---\n- :tracker\n- :status\n- :subject\n", ['tracker', 'status', 'subject']];
        yield 'symbol keys (query options)' => ["---\n:totalable_names:\n- :hours\n:display_type: list\n", ['totalable_names' => ['hours'], 'display_type' => 'list']];
        yield 'quoted symbol key' => ["---\n:\"foo bar\": 1\n", ['foo bar' => 1]];
        yield 'ruby/symbol tags' => ["---\n- !ruby/symbol foo\n- !ruby/sym bar\n", ['foo', 'bar']];
        yield 'binary values (Ruby 1.9 UTF-8 strings)' => ["---\n- !binary |-\n  w6TDtsO8\n- plain\n", ['äöü', 'plain']];
        yield 'binary keys' => ["---\n? !binary |-\n  Zm9v\n: bar\nx: 1\n", ['foo' => 'bar', 'x' => 1]];
        yield 'Syck map (Redmine 1.x)' => ["--- !map:HashWithIndifferentAccess \nfoo: bar\n", ['foo' => 'bar']];
        yield 'Syck seq' => ["--- !seq:Array \n- a\n- b\n", ['a', 'b']];
        yield 'Ruby Set' => ["--- !ruby/object:Set\nhash:\n  a: true\n  b: true\n", ['a', 'b']];
        yield 'TimeWithZone' => ["---\nd: !ruby/object:ActiveSupport::TimeWithZone\n  utc: 2020-01-02 03:04:05.000000000 Z\n  zone: !ruby/object:ActiveSupport::TimeZone\n    name: Etc/UTC\n  time: 2020-01-02 03:04:05.000000000 Z\n", ['d' => '2020-01-02 03:04:05']];
        yield 'sort criteria' => ["---\n- - priority\n  - desc\n- - id\n  - desc\n", [['priority', 'desc'], ['id', 'desc']]];
        yield 'empty list' => ["--- []\n", []];
        yield 'scalar' => ["--- foo\n", 'foo'];
        yield 'user preferences' => [
            "---\n:no_self_notified: '1'\n:my_page_layout:\n  left:\n  - issuesassignedtome\n:my_page_settings: {}\n:comments_sorting: desc\n",
            ['no_self_notified' => '1', 'my_page_layout' => ['left' => ['issuesassignedtome']], 'my_page_settings' => [], 'comments_sorting' => 'desc'],
        ];
    }

    #[DataProvider('cases')]
    public function testDecode(string $yaml, mixed $expected): void
    {
        self::assertTrue(RubyYaml::isYaml($yaml));
        self::assertSame($expected, RubyYaml::decode($yaml));
    }

    public function testSerializedTypeRoundTrip(): void
    {
        $yaml = "--- !ruby/object:ActionController::Parameters\nparameters: !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n  status_id: o\npermitted: false\n";
        $json = RedmineSerializedType::encode(RubyYaml::decode($yaml));
        self::assertSame('{"status_id":"o"}', $json);
        self::assertSame(['status_id' => 'o'], RedmineSerializedType::decode($json));
        self::assertFalse(RubyYaml::isYaml('plain value'));
    }
}
