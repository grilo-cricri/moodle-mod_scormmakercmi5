<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_scormmakercmi5\local\lrs;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_validator::class)]
/**
 * Tests for the structural xAPI 1.0.3 validation of statements (design §7.1, §3.13).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\statement_validator
 */
final class statement_validator_test extends \advanced_testcase {
    /** @var string Registration of the base statement. */
    private const REG = '1d6d4f4e-8a4b-4d6f-9d6c-2f7a3b4c5d6e';

    /**
     * A valid statement that uses most of the xAPI properties, decoded as the LRS decodes it.
     *
     * @return array
     */
    private static function base(): array {
        $json = '{
            "id": "0b3f7c1e-5a2d-4c8e-9f10-2a3b4c5d6e7f",
            "timestamp": "2026-09-30T12:00:00.123Z",
            "version": "1.0.3",
            "actor": {"objectType": "Agent", "name": "Learner", "account": {"homePage": "https://lms.example", "name": "7"}},
            "verb": {"id": "http://adlnet.gov/expapi/verbs/answered", "display": {"pt-BR": "respondeu", "en-US": "answered"}},
            "object": {
                "objectType": "Activity",
                "id": "https://scormmaker.com.br/xapi/curso/c1/questao/q1",
                "definition": {
                    "type": "http://adlnet.gov/expapi/activities/cmi.interaction",
                    "name": {"pt-BR": "Pergunta"},
                    "description": {"pt-BR": ""},
                    "moreInfo": "https://scormmaker.com.br/ajuda",
                    "interactionType": "choice",
                    "correctResponsesPattern": ["a[,]b"],
                    "choices": [{"id": "a", "description": {"pt-BR": "A"}}, {"id": "b", "description": {"pt-BR": "B"}}],
                    "extensions": {"https://scormmaker.com.br/xapi/extensao/x": {"any": [1, 2]}}
                }
            },
            "result": {
                "score": {"raw": 5, "min": 0, "max": 10, "scaled": 0.5},
                "success": true,
                "completion": false,
                "response": "a[,]b",
                "duration": "PT1M2.5S",
                "extensions": {"https://w3id.org/xapi/cmi5/result/extensions/progress": 40}
            },
            "context": {
                "registration": "' . self::REG . '",
                "contextActivities": {
                    "parent": [{"id": "https://lms.example/au/1"}],
                    "grouping": {"objectType": "Activity", "id": "https://publisher.example/au/1"},
                    "category": [{"id": "https://scormmaker.com.br/xapi/perfil/v1"}]
                },
                "instructor": {"mbox": "mailto:teacher@example.com"},
                "team": {"objectType": "Group", "member": [{"mbox_sha1sum": "' . str_repeat('a', 40) . '"}]},
                "revision": "3",
                "platform": "Moodle",
                "language": "pt-BR",
                "statement": {"objectType": "StatementRef", "id": "9e1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d"},
                "extensions": {"https://w3id.org/xapi/cmi5/context/extensions/sessionid": "s-1"}
            }
        }';
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Validates and returns the lrs_exception, or null when the statement is valid.
     *
     * @param array $statement Statement.
     * @return lrs_exception|null
     */
    private static function error_of(array $statement): ?lrs_exception {
        try {
            statement_validator::validate($statement);
        } catch (lrs_exception $e) {
            return $e;
        }
        return null;
    }

    /**
     * Asserts a 400 err_invalidstatement whose message names the field.
     *
     * @param array $statement Statement.
     * @param string $field Field that must appear in the message.
     */
    private function assert_invalid(array $statement, string $field): void {
        $e = self::error_of($statement);
        $this->assertNotNull($e, "expected 400 for {$field}");
        $this->assertSame(400, $e->httpstatus);
        $this->assertSame('err_invalidstatement', $e->errorcode);
        $this->assertSame($field, $e->a, 'the message names exactly this field');
        $this->assertMatchesRegularExpression('/^xAPI \d+(\.\d+)*$/', (string)$e->rule);
    }

    /**
     * The base statement and its lighter forms are valid; a statement without id is valid here (cmi5 rule 1 is 403).
     */
    public function test_valid(): void {
        $this->assertNull(self::error_of(self::base()));

        $s = self::base();
        unset($s['id']);
        $this->assertNull(self::error_of($s), 'the id is required by cmi5 (Task 14, 403), not by xAPI');

        $minimal = [
            'actor' => ['mbox' => 'mailto:a@b.c'],
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/experienced'],
            'object' => ['id' => 'https://example.com/a'],
            'result' => [],
            'context' => ['contextActivities' => [], 'extensions' => []],
        ];
        $this->assertNull(self::error_of($minimal), 'empty objects decode as []');

        $s = self::base();
        $s['object'] = ['objectType' => 'StatementRef', 'id' => '9e1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d'];
        unset($s['context']['revision'], $s['context']['platform']);
        $this->assertNull(self::error_of($s));

        $s = self::base();
        $s['object'] = ['objectType' => 'Agent', 'openid' => 'https://id.example/x'];
        unset($s['context']['revision'], $s['context']['platform']);
        $this->assertNull(self::error_of($s));

        $s = self::base();
        $s['actor'] = ['objectType' => 'Group', 'name' => 'G', 'member' => [['mbox' => 'mailto:a@b.c']]];
        $this->assertNull(self::error_of($s), 'anonymous group');

        $s = self::base();
        $sub = self::base();
        unset($sub['id'], $sub['version']);
        $sub['objectType'] = 'SubStatement';
        $s['object'] = $sub;
        unset($s['context']['revision'], $s['context']['platform']);
        $this->assertNull(self::error_of($s), 'SubStatement (its own context keeps revision: its object is an Activity)');

        $s = self::base();
        $s['version'] = '1.0';
        $s['timestamp'] = '2026-09-30T09:00:00-03:00';
        $s['stored'] = '2026-09-30T12:00:01Z';
        $s['authority'] = ['objectType' => 'Agent', 'account' => ['homePage' => 'https://lms.example', 'name' => 'lms']];
        $s['attachments'] = [[
            'usageType' => 'http://adlnet.gov/expapi/attachments/signature',
            'display' => ['en-US' => 'Signature'],
            'contentType' => 'application/octet-stream',
            'length' => 10,
            'sha2' => str_repeat('b', 64),
            'fileUrl' => 'https://files.example/x',
        ]];
        $this->assertNull(self::error_of($s));

        $s = self::base();
        $s['result']['score'] = ['raw' => 0, 'min' => 0, 'max' => 0, 'scaled' => 0];
        $this->assertNull(self::error_of($s), 'a question worth 0 points (SCORM Maker sends min = max = 0)');
    }

    /**
     * verb.id fits the stmt.verb column (CHAR(255), design §3.13): 255 characters are valid, 256 are a 400.
     */
    public function test_verb_length(): void {
        $prefix = 'https://example.com/';
        $s = self::base();
        $s['verb']['id'] = $prefix . str_repeat('v', 255 - strlen($prefix));
        $this->assertNull(self::error_of($s));

        $s['verb']['id'] = $prefix . str_repeat('ç', 255 - strlen($prefix));
        $this->assertNull(self::error_of($s), 'characters, not bytes');

        $s['verb']['id'] = $prefix . str_repeat('v', 256 - strlen($prefix));
        $this->assert_invalid($s, 'verb.id');
    }

    /**
     * Each change of the base statement gives 400 naming the field.
     *
     * @dataProvider invalid_provider
     * @param callable $change Changes the base statement.
     * @param string $field Field named in the message.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(callable $change, string $field): void {
        $s = self::base();
        $change($s);
        $this->assert_invalid($s, $field);
    }

    /**
     * Invalid statements.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        $sub = function (array &$s): void {
            $s['object'] = ['objectType' => 'SubStatement', 'actor' => ['mbox' => 'mailto:a@b.c'],
                'verb' => ['id' => 'https://v.example/x'], 'object' => ['id' => 'https://a.example/x']];
        };
        return [
            'id not a UUID' => [function (array &$s): void {
                $s['id'] = 'abc';
            }, 'id'],
            'id not a string' => [function (array &$s): void {
                $s['id'] = 12;
            }, 'id'],
            'no actor' => [function (array &$s): void {
                unset($s['actor']);
            }, 'actor'],
            'mbox without mailto:' => [function (array &$s): void {
                $s['actor'] = ['mbox' => 'x@y'];
            }, 'actor.mbox'],
            'two identifiers' => [function (array &$s): void {
                $s['actor']['mbox'] = 'mailto:x@y.z';
            }, 'actor'],
            'no identifier' => [function (array &$s): void {
                $s['actor'] = ['objectType' => 'Agent', 'name' => 'x'];
            }, 'actor'],
            'account with extra property' => [function (array &$s): void {
                $s['actor']['account']['x'] = 1;
            }, 'actor.account.x'],
            'account without name' => [function (array &$s): void {
                unset($s['actor']['account']['name']);
            }, 'actor.account.name'],
            'mbox_sha1sum not sha1' => [function (array &$s): void {
                $s['actor'] = ['mbox_sha1sum' => 'zz'];
            }, 'actor.mbox_sha1sum'],
            'actor objectType unknown' => [function (array &$s): void {
                $s['actor']['objectType'] = 'Person';
            }, 'actor.objectType'],
            'anonymous group without member' => [function (array &$s): void {
                $s['actor'] = ['objectType' => 'Group'];
            }, 'actor.member'],
            'group inside a group' => [function (array &$s): void {
                $s['actor'] = ['objectType' => 'Group', 'member' => [['objectType' => 'Group', 'member' => []]]];
            }, 'actor.member[0].objectType'],
            'actor objectType null' => [function (array &$s): void {
                $s['actor']['objectType'] = null;
            }, 'actor.objectType'],
            'object objectType null' => [function (array &$s): void {
                $s['object']['objectType'] = null;
            }, 'object.objectType'],
            'version null' => [function (array &$s): void {
                $s['version'] = null;
            }, 'version'],
            'actor not an object' => [function (array &$s): void {
                $s['actor'] = 'me';
            }, 'actor'],
            'verb.id not an IRI' => [function (array &$s): void {
                $s['verb']['id'] = 'passed';
            }, 'verb.id'],
            'verb.id with a space' => [function (array &$s): void {
                $s['verb']['id'] = 'https://example.com/a b';
            }, 'verb.id'],
            'verb display bad language' => [function (array &$s): void {
                $s['verb']['display'] = ['not a tag' => 'x'];
            }, 'verb.display'],
            'verb display value not a string' => [function (array &$s): void {
                $s['verb']['display'] = ['en' => 1];
            }, 'verb.display'],
            'no object.id' => [function (array &$s): void {
                unset($s['object']['id']);
            }, 'object.id'],
            'object objectType unknown' => [function (array &$s): void {
                $s['object']['objectType'] = 'Thing';
            }, 'object.objectType'],
            'definition unknown property' => [function (array &$s): void {
                $s['object']['definition']['foo'] = 1;
            }, 'object.definition.foo'],
            'interactionType unknown' => [function (array &$s): void {
                $s['object']['definition']['interactionType'] = 'essay';
            }, 'object.definition.interactionType'],
            'duplicate choice id' => [function (array &$s): void {
                $s['object']['definition']['choices'][1]['id'] = 'a';
            }, 'object.definition.choices'],
            'correctResponsesPattern not strings' => [function (array &$s): void {
                $s['object']['definition']['correctResponsesPattern'] = [1];
            }, 'object.definition.correctResponsesPattern'],
            'extension key not an IRI' => [function (array &$s): void {
                $s['object']['definition']['extensions'] = ['progress' => 1];
            }, 'object.definition.extensions'],
            'StatementRef id not a UUID' => [function (array &$s): void {
                $s['object'] = ['objectType' => 'StatementRef', 'id' => 'x'];
                unset($s['context']['revision'], $s['context']['platform']);
            }, 'object.id'],
            'nested SubStatement' => [function (array &$s) use ($sub): void {
                $sub($s);
                $s['object']['object'] = ['objectType' => 'SubStatement'];
            }, 'object.object.objectType'],
            'SubStatement with id' => [function (array &$s) use ($sub): void {
                $sub($s);
                $s['object']['id'] = '0b3f7c1e-5a2d-4c8e-9f10-2a3b4c5d6e7f';
            }, 'object.id'],
            'scaled not a number' => [function (array &$s): void {
                $s['result']['score']['scaled'] = 'abc';
            }, 'result.score.scaled'],
            'scaled above 1' => [function (array &$s): void {
                $s['result']['score']['scaled'] = 1.5;
            }, 'result.score.scaled'],
            'scaled below -1' => [function (array &$s): void {
                $s['result']['score']['scaled'] = -1.01;
            }, 'result.score.scaled'],
            'scaled boolean' => [function (array &$s): void {
                $s['result']['score']['scaled'] = true;
            }, 'result.score.scaled'],
            'scaled not finite' => [function (array &$s): void {
                $s['result']['score']['scaled'] = INF;
            }, 'result.score.scaled'],
            'raw above max' => [function (array &$s): void {
                $s['result']['score']['raw'] = 11;
            }, 'result.score.raw'],
            'min above max' => [function (array &$s): void {
                $s['result']['score'] = ['min' => 5, 'max' => 1];
            }, 'result.score.min'],
            'success not a boolean' => [function (array &$s): void {
                $s['result']['success'] = 'true';
            }, 'result.success'],
            'response not a string' => [function (array &$s): void {
                $s['result']['response'] = 1;
            }, 'result.response'],
            'duration invalid' => [function (array &$s): void {
                $s['result']['duration'] = 'PTx';
            }, 'result.duration'],
            'result unknown property' => [function (array &$s): void {
                $s['result']['grade'] = 1;
            }, 'result.grade'],
            'duration fraction not on the last component' => [function (array &$s): void {
                $s['result']['duration'] = 'PT1.5H30M';
            }, 'result.duration'],
            'duration with a trailing newline' => [function (array &$s): void {
                $s['result']['duration'] = "PT1S\n";
            }, 'result.duration'],
            'timestamp with a trailing newline' => [function (array &$s): void {
                $s['timestamp'] = "2026-01-01T00:00:00Z\n";
            }, 'timestamp'],
            'language with an underscore' => [function (array &$s): void {
                $s['verb']['display'] = ['en_US' => 'x'];
            }, 'verb.display'],
            'sha2 of 57 characters' => [function (array &$s): void {
                $s['attachments'] = [['usageType' => 'https://u.example/x', 'display' => ['en' => 'x'],
                    'contentType' => 'text/plain', 'length' => 1, 'sha2' => str_repeat('c', 57),
                    'fileUrl' => 'https://f.example/x']];
            }, 'attachments[0].sha2'],
            'timestamp invalid' => [function (array &$s): void {
                $s['timestamp'] = 'ontem';
            }, 'timestamp'],
            'registration not a UUID' => [function (array &$s): void {
                $s['context']['registration'] = 'x';
            }, 'context.registration'],
            'contextActivities unknown key' => [function (array &$s): void {
                $s['context']['contextActivities']['sibling'] = [];
            }, 'context.contextActivities.sibling'],
            'contextActivities activity without id' => [function (array &$s): void {
                $s['context']['contextActivities']['parent'] = [['objectType' => 'Activity']];
            }, 'context.contextActivities.parent[0].id'],
            'team not a group' => [function (array &$s): void {
                $s['context']['team'] = ['mbox' => 'mailto:a@b.c'];
            }, 'context.team'],
            'language invalid' => [function (array &$s): void {
                $s['context']['language'] = 'português';
            }, 'context.language'],
            'platform with an Agent object' => [function (array &$s): void {
                $s['object'] = ['objectType' => 'Agent', 'mbox' => 'mailto:a@b.c'];
                unset($s['context']['revision']);
            }, 'context.platform'],
            'revision with a StatementRef object' => [function (array &$s): void {
                $s['object'] = ['objectType' => 'StatementRef', 'id' => '9e1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d'];
                unset($s['context']['platform']);
            }, 'context.revision'],
            'context.statement not a StatementRef' => [function (array &$s): void {
                $s['context']['statement'] = ['id' => '9e1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d'];
            }, 'context.statement'],
            'unknown top-level property' => [function (array &$s): void {
                $s['foo'] = 1;
            }, 'foo'],
            'version 2.0.0' => [function (array &$s): void {
                $s['version'] = '2.0.0';
            }, 'version'],
            'stored invalid' => [function (array &$s): void {
                $s['stored'] = 'now';
            }, 'stored'],
            'attachment without fileUrl' => [function (array &$s): void {
                $s['attachments'] = [['usageType' => 'https://u.example/x', 'display' => ['en' => 'x'],
                    'contentType' => 'text/plain', 'length' => 1, 'sha2' => str_repeat('c', 64)]];
            }, 'attachments[0].fileUrl'],
            'NUL in a string' => [function (array &$s): void {
                $s['result']['response'] = "a\0b";
            }, 'result.response'],
            'statement is a list' => [function (array &$s): void {
                $s = [1, 2];
            }, 'statement'],
        ];
    }

    /**
     * Every duration of the ISO 8601 4.4.3.2 grammar is valid, years and months included (xAPI 4.6).
     */
    public function test_full_durations(): void {
        foreach (['P3Y1M29DT4H35M59.14S', 'PT1.5M', 'P0Y0M0DT0H1M3S', 'P1W', 'PT0,5S', 'P1Y'] as $d) {
            $s = self::base();
            $s['result']['duration'] = $d;
            $this->assertNull(self::error_of($s), $d);
        }
    }

    /**
     * normalize() turns every contextActivities value into a list, also in a SubStatement; lists stay the same.
     */
    public function test_normalize(): void {
        $s = self::base();
        $sub = self::base();
        unset($sub['id'], $sub['version']);
        $sub['objectType'] = 'SubStatement';
        $s['object'] = $sub;
        unset($s['context']['revision'], $s['context']['platform']);
        $s['context']['contextActivities']['other'] = ['id' => 'https://o.example/1'];
        statement_validator::validate($s);

        $n = statement_validator::normalize($s);
        $grouping = [['objectType' => 'Activity', 'id' => 'https://publisher.example/au/1']];
        $this->assertSame($grouping, $n['context']['contextActivities']['grouping']);
        $this->assertSame([['id' => 'https://o.example/1']], $n['context']['contextActivities']['other']);
        $this->assertSame($s['context']['contextActivities']['parent'], $n['context']['contextActivities']['parent']);
        $this->assertSame($grouping, $n['object']['context']['contextActivities']['grouping']);
        $this->assertSame(
            $s['object']['context']['contextActivities']['category'],
            $n['object']['context']['contextActivities']['category']
        );
        statement_validator::validate($n);

        $minimal = ['actor' => ['mbox' => 'mailto:a@b.c'], 'verb' => ['id' => 'https://v.example/x'],
            'object' => ['id' => 'https://a.example/x']];
        $this->assertSame($minimal, statement_validator::normalize($minimal));
    }

    /**
     * contains_nul finds U+0000 in any string or key, at any depth.
     */
    public function test_contains_nul(): void {
        $decoded = json_decode('{"a": [{"b": "x\u0000y"}]}', true, 64, JSON_THROW_ON_ERROR);
        $this->assertTrue(statement_validator::contains_nul($decoded));
        $this->assertTrue(statement_validator::contains_nul(["k\0" => 1]));
        $this->assertTrue(statement_validator::contains_nul("\0"));
        $this->assertFalse(statement_validator::contains_nul(self::base()));
        $this->assertFalse(statement_validator::contains_nul(null));
        $this->assertFalse(statement_validator::contains_nul(1.5));
    }

    /**
     * normalize_batch: an object is a batch of one, a list stays, anything else is a 400.
     */
    public function test_normalize_batch(): void {
        $s = self::base();
        $this->assertSame([$s], statement_validator::normalize_batch($s));
        $this->assertSame([$s, $s], statement_validator::normalize_batch([$s, $s]));
        $this->assertSame([], statement_validator::normalize_batch([]));

        foreach (['abc', 1, null, true, [$s, 'x'], [[1, 2]]] as $bad) {
            try {
                statement_validator::normalize_batch($bad);
                $this->fail('expected 400 for ' . json_encode($bad));
            } catch (lrs_exception $e) {
                $this->assertSame(400, $e->httpstatus);
                $this->assertSame('err_invalidbatch', $e->errorcode);
            }
        }
    }
}
