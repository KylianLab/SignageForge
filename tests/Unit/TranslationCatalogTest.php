<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ScheduleRule;
use App\Enum\MediaType;
use App\Enum\MembershipRole;
use App\Enum\Orientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every English sentence shown in the interface is a translation key. This
 * collects them from the templates and the code, and checks that each
 * catalog translates all of them, and nothing else.
 */
final class TranslationCatalogTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'French' => ['fr'];
        yield 'Dutch' => ['nl'];
    }

    #[DataProvider('locales')]
    public function testTheCatalogTranslatesEveryMessage(string $locale): void
    {
        $catalog = self::catalog($locale);
        $keys = self::keys();

        self::assertSame([], array_values(array_diff($keys, array_keys($catalog))), 'Missing translations');
        self::assertSame([], array_values(array_diff(array_keys($catalog), $keys)), 'Translations of keys no longer used');
    }

    #[DataProvider('locales')]
    public function testTranslationsKeepTheirPlaceholders(string $locale): void
    {
        foreach (self::catalog($locale) as $key => $translation) {
            self::assertNotSame('', trim($translation), $key);
            preg_match_all('/%\w+%/', $key, $expected);
            preg_match_all('/%\w+%/', $translation, $actual);
            $expected = array_unique($expected[0]);
            $actual = array_unique($actual[0]);
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, \sprintf('Placeholders of "%s"', $key));
            self::assertSame(substr_count($key, '|'), substr_count($translation, '|'), \sprintf('Plural forms of "%s"', $key));
        }
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        $catalog = Yaml::parseFile(self::ROOT.'/translations/messages.'.$locale.'.yaml');
        self::assertIsArray($catalog);

        /** @var array<string, string> $catalog */
        return $catalog;
    }

    /** @return list<string> */
    private static function keys(): array
    {
        $quoted = '\'((?:[^\'\\\\]|\\\\.)*)\'';
        $keys = [];

        foreach ((new Finder())->files()->in(self::ROOT.'/templates')->name('*.twig') as $file) {
            $twig = $file->getContents();
            // 'Text'|trans
            preg_match_all('/'.$quoted.'\s*\|\s*trans\b/', $twig, $m);
            array_push($keys, ...$m[1]);
            // (condition ? 'One' : 'Other')|trans
            preg_match_all('/\?\s*'.$quoted.'\s*:\s*'.$quoted.'\s*\)\s*\|\s*trans\b/', $twig, $m);
            array_push($keys, ...$m[1], ...$m[2]);
            // {key: 'Label', ...}[value]|trans, and {% set names = {key: 'Label'} %} used as names[x]|trans
            $map = '\{((?:[\w\'-]+\s*:\s*'.$quoted.'\s*,?\s*)+)\}';
            preg_match_all('/(?:'.$map.'\[[^\]]+\](?:\|default\([^)]*\))?\s*\|\s*trans|\{%\s*set\s+\w+\s*=\s*'.$map.'\s*%\})/', $twig, $maps);
            $maps[1] = array_merge(array_filter($maps[1]), array_filter($maps[3]));
            foreach ($maps[1] as $map) {
                preg_match_all('/:\s*'.$quoted.'/', $map, $m);
                array_push($keys, ...$m[1]);
            }
        }

        foreach ((new Finder())->files()->in(self::ROOT.'/src')->name('*.php') as $file) {
            $php = $file->getContents();
            // flash('success', 'Text'), ->trans('Text'), t('Text')
            preg_match_all('/(?:flash\(\'\w+\',|->trans\(|\bt\()\s*'.$quoted.'/', $php, $m);
            array_push($keys, ...$m[1]);
        }

        // Validation errors and update blockers: the sentences of `match (true)` arms.
        foreach (['Controller/Dashboard/ScheduleController.php', 'Controller/Dashboard/ScreenController.php', 'Controller/Dashboard/UserController.php', 'Controller/InstallController.php', 'Update/WebUpdater.php'] as $path) {
            preg_match_all('/=>\s*'.$quoted.',\n/', (string) file_get_contents(self::ROOT.'/src/'.$path), $m);
            array_push($keys, ...array_filter($m[1], static fn (string $s): bool => str_contains($s, ' ')));
        }

        foreach ([MediaType::cases(), MembershipRole::cases(), Orientation::cases()] as $cases) {
            foreach ($cases as $case) {
                $keys[] = $case->label();
            }
        }
        array_push($keys, ...array_values(ScheduleRule::DAY_NAMES));

        $keys = array_map(static fn (string $key): string => stripcslashes($key), $keys);
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
