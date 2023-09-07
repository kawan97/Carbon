<?php

include_once __DIR__.'/config.php';

date_default_timezone_set('UTC');
setlocale(LC_ALL, 'en');
ini_set('html_errors', 0);

if (function_exists('xdebug_disable')) {
    ini_set('xdebug.overload_var_dump', 0);
    ini_set('xdebug.mode', 'none');
    xdebug_disable();
}

if (!in_array('--wrapped', $argv ?? [], true) &&
    @in_array(ini_get('xdebug.mode'), ['develop', 'coverage', 'debug', 'gcstats', 'profile', 'trace'], true)
) {
    passthru(PHP_BINARY.' -d xdebug.mode=none '.escapeshellarg(__FILE__).' --wrapped', $resultCode);
    exit($resultCode);
}

chdir(__DIR__.'/..');
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/methods.php';

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\Carbonite;
use Carbon\CarbonPeriod;
use Carbon\CarbonTimeZone;
use Carbon\Factory;
use Carbon\FactoryImmutable;
use Carbon\Language;
use Carbon\Translator;

function carbonDocVarDump()
{
    call_user_func_array('var_dump', array_map(function ($value) {
        if (is_object($value) && method_exists($value, '__debugInfo')) {
            return $value->__debugInfo();
        }

        return $value;
    }, func_get_args()));
}

function isHistoryUpToDate()
{
    if (!file_exists('history.json')) {
        return false;
    }

    $versions = json_decode(file_get_contents('https://packagist.org/p/nesbot/carbon.json'), true)['packages']['nesbot/carbon'];
    $maxVersion = '1.0.0';

    foreach ($versions as $version => $data) {
        if (version_compare($version, $maxVersion) > 0) {
            $maxVersion = $version;
        }
    }

    $versionData = $versions[$maxVersion];

    return Carbon::parse($versionData['time'])->timestamp < filemtime('history.json');
}

function historyLine($event, $version, $ref)
{
    $ref = empty($ref) ? '<em>no arguments</em>' : "<code>$ref</code>";

    return "<tr><td>$event</td><td>$version</td><td>$ref</td></tr>";
}

$globalHistory = @json_decode(file_get_contents('history.json'), JSON_OBJECT_AS_ARRAY);

Carbon::macro('getAvailableMacroLocales', function () {
    $locales = [];

    foreach (Carbon::getAvailableLocales() as $locale) {
        $locales[explode('_', $locale)[0]] = 1;
    }

    return array_keys($locales);
});

Carbon::macro('getAllMethods', function () use ($globalHistory) {
    foreach (@methods(false, false) as [$carbonObject, $className, $method, $parameters, $return, $description, $dateTimeObject, $info]) {
        $classes = trim(implode(' ', [
            strpos($description ?? '', '@deprecated') !== false ? 'deprecated' : '',
        ]));

        if (method_exists($dateTimeObject, $method)) {
            $dateClass = get_class($dateTimeObject);
            $rcCarbon = new ReflectionMethod($className, $method);
            $rcDate = new ReflectionMethod($dateClass, $method);

            if ($rcCarbon == $rcDate) {
                $dateClass = trim($dateClass, '/\\');

                yield strpos($dateClass, 'Symfony\\') === 0
                    ? [
                        'info'        => $info,
                        'name'        => $method,
                        'classes'     => $classes,
                        'return'      => $return,
                        'prototype'   => '<em>Method from Symfony interhited class</em>',
                        'className'   => preg_replace('/^Carbon\\\\/', '', $className),
                        'description' => 'See '.$dateClass.'::'.$method,
                        'history'     => '',
                    ]
                    : [
                        'info'        => $info,
                        'name'        => $method,
                        'classes'     => $classes,
                        'return'      => $return,
                        'prototype'   => '<em>Native PHP method</em>',
                        'className'   => preg_replace('/^Carbon\\\\/', '', $className),
                        'description' => 'See <a href="http://php.net/manual/en/'.strtolower($dateClass.'.'.$method).'.php">PHP documentation for '.$dateClass.'::'.$method.'</a>',
                        'history'     => '',
                    ];

                continue;
            }
        }

        $history = '';
        $key = "$className::$method";
        $parameters = implode(', ', $parameters ?: []);

        if (is_array($globalHistory) && isset($globalHistory[$key])) {
            $ref = implode(', ', reset($globalHistory[$key]) ?: ['']);
            $parameters = $ref;
            $version = key($globalHistory[$key]);

            while (($prototype = next($globalHistory[$key])) !== false) {
                $prototype = implode(', ', $prototype);
                if ($prototype !== $ref) {
                    $history .= historyLine('Prototype changed', $version, $ref);
                    $ref = $prototype;
                }
                $version = key($globalHistory[$key]);
            }

            $history .= historyLine('Method added', $version, $ref);
        }

        $description = preg_replace(
            '/@deprecated\s(([^\n]+)(\n [^\n])*)\n/',
            '<div class="alert alert-warning">$1</div>',
            $description ?? ''
        );
        $description = preg_replace(
            '/@see\s+(https?:\/\/(\S+))/',
            'See <a href="$1">$2</a>',
            $description
        );
        $description = preg_replace(
            '/\n *\n/',
            '<br><br>',
            trim($description)
        );

        yield [
            'info'        => $info,
            'name'        => $method,
            'classes'     => $classes,
            'return'      => $return,
            'prototype'   => empty($parameters) ? '<em>no arguments</em>' : "<code>$parameters</code>",
            'className'   => preg_replace('/^Carbon\\\\/', '', $className),
            'description' => $description,
            'history'     => empty($info) ? "<table class='info-table method-history'>$history</table>" : '',
        ];
    }
});

Carbon::macro('describeIsoFormat', function ($code) {
    return [
        'D'         => 'Day of month number (from 1 to 31)',
        'DD'        => 'Day of month number with trailing zero (from 01 to 31)',
        'Do'        => 'Day of month with ordinal suffix (from 1st to 31th), translatable',
        'd'         => 'Day of week number (from 0 (Sunday) to 6 (Saturday))',
        'dd'        => 'Minified day name (from Su to Sa), translatable',
        'ddd'       => 'Short day name (from Sun to Sat), translatable',
        'dddd'      => 'Day name (from Sunday to Saturday), translatable',
        'DDD'       => 'Day of year number (from 1 to 366)',
        'DDDD'      => 'Day of year number with trailing zeros (3 digits, from 001 to 366)',
        'DDDo'      => 'Day of year number with ordinal suffix (from 1st to 366th), translatable',
        'e'         => 'Day of week number (from 0 (Sunday) to 6 (Saturday)), similar to "d" but this one is translatable (takes first day of week of the current locale)',
        'E'         => 'Day of week number (from 1 (Monday) to 7 (Sunday))',
        'H'         => 'Hour from 0 to 23',
        'HH'        => 'Hour with trailing zero from 00 to 23',
        'h'         => 'Hour from 0 to 12',
        'hh'        => 'Hour with trailing zero from 00 to 12',
        'k'         => 'Hour from 1 to 24',
        'kk'        => 'Hour with trailing zero from 01 to 24',
        'm'         => 'Minute from 0 to 59',
        'mm'        => 'Minute with trailing zero from 00 to 59',
        'a'         => 'Meridiem am/pm',
        'A'         => 'Meridiem AM/PM',
        's'         => 'Second from 0 to 59',
        'ss'        => 'Second with trailing zero from 00 to 59',
        'S'         => 'Second tenth',
        'SS'        => 'Second hundredth (on 2 digits with trailing zero)',
        'SSS'       => 'Millisecond (on 3 digits with trailing zeros)',
        'SSSS'      => 'Second ten thousandth (on 4 digits with trailing zeros)',
        'SSSSS'     => 'Second hundred thousandth (on 5 digits with trailing zeros)',
        'SSSSSS'    => 'Microsecond (on 6 digits with trailing zeros)',
        'SSSSSSS'   => 'Second ten millionth (on 7 digits with trailing zeros)',
        'SSSSSSSS'  => 'Second hundred millionth (on 8 digits with trailing zeros)',
        'SSSSSSSSS' => 'Nanosecond (on 9 digits with trailing zeros)',
        'M'         => 'Month from 1 to 12',
        'MM'        => 'Month with trailing zero from 01 to 12',
        'MMM'       => 'Short month name, translatable',
        'MMMM'      => 'Month name, translatable',
        'Mo'        => 'Month with ordinal suffix from 1st to 12th, translatable',
        'OY'        => 'Year number with alternative numbers such as ۱۹۹۸ for 1998 if locale is fa',
        'OM'        => 'Month number with alternative numbers such as ၀၂ for 2 if locale is my_MM',
        'OD'        => 'Day number with alternative numbers such as 三 for 3 if locale is ja_JP',
        'OH'        => '24-hours number with alternative numbers such as ႑႓ for 13 if locale is shn_MM',
        'Oh'        => '12-hours number with alternative numbers such as 十一 for 11 if locale is lzh_TW',
        'Om'        => 'Minute number with alternative numbers such as ୫୭ for 57 if locale is or',
        'Os'        => 'Second number with alternative numbers such as 十五 for 15 if locale is ja_JP',
        'Q'         => 'Quarter from 1 to 4',
        'Qo'        => 'Quarter with ordinal suffix from 1st to 4th, translatable',
        'G'         => 'ISO week year (see <a href="https://en.wikipedia.org/wiki/ISO_week_date" target="_blank">ISO week date</a>)',
        'GG'        => 'ISO week year (on 2 digits with trailing zero)',
        'GGG'       => 'ISO week year (on 3 digits with trailing zeros)',
        'GGGG'      => 'ISO week year (on 4 digits with trailing zeros)',
        'GGGGG'     => 'ISO week year (on 5 digits with trailing zeros)',
        'g'         => 'Week year according to locale settings, translatable',
        'gg'        => 'Week year according to locale settings (on 2 digits with trailing zero), translatable',
        'ggg'       => 'Week year according to locale settings (on 3 digits with trailing zeros), translatable',
        'gggg'      => 'Week year according to locale settings (on 4 digits with trailing zeros), translatable',
        'ggggg'     => 'Week year according to locale settings (on 5 digits with trailing zeros), translatable',
        'W'         => 'ISO week number in the year (see <a href="https://en.wikipedia.org/wiki/ISO_week_date" target="_blank">ISO week date</a>)',
        'WW'        => 'ISO week number in the year (on 2 digits with trailing zero)',
        'Wo'        => 'ISO week number in the year with ordinal suffix, translatable',
        'w'         => 'Week number in the year according to locale settings, translatable',
        'ww'        => 'Week number in the year according to locale settings (on 2 digits with trailing zero)',
        'wo'        => 'Week number in the year according to locale settings with ordinal suffix, translatable',
        'x'         => 'Millisecond-precision timestamp (same as <code>date.getTime()</code> in JavaScript)',
        'X'         => 'Timestamp (number of seconds since 1970-01-01)',
        'Y'         => 'Full year from -9999 to 9999',
        'YY'        => 'Year on 2 digits from 00 to 99',
        'YYYY'      => 'Year on 4 digits from 0000 to 9999',
        'YYYYY'     => 'Year on 5 digits from 00000 to 09999',
        'YYYYYY'    => 'Year on 5 digits with sign from -09999 to +09999',
        'z'         => 'Abbreviated time zone name',
        'zz'        => 'Time zone name',
        'Z'         => 'Time zone offset HH:mm',
        'ZZ'        => 'Time zone offset HHmm',
    ][$code] ?? '';
});

$template = file_get_contents('template.src.html');

function filterBackers(array $list, ?array $include, ?array $exclude): array
{
    return array_filter($list, static function ($item) use ($include, $exclude) {
        if (($item['role'] ?? null) !== 'BACKER') {
            return false;
        }

        $type = $item['type'] ?? null;

        if ($include && !in_array($type, $include, true)) {
            return false;
        }

        return !$exclude || !in_array($type, $exclude, true);
    });
}

function getAllBackers(): array
{
    return array_map(static function (array $member) {
        $createdAt = CarbonImmutable::parse($member['createdAt']);
        $lastTransactionAt = CarbonImmutable::parse($member['lastTransactionAt']);

        if ($createdAt->format('d H:i:s.u') > $lastTransactionAt->format('d H:i:s.u')) {
            $createdAt = $createdAt
                ->setDay($lastTransactionAt->day)
                ->modify($lastTransactionAt->format('H:i:s.u'));
        }

        $monthlyContribution = (float) ($member['totalAmountDonated'] / ceil($createdAt->floatDiffInMonths()));

        if (
            $lastTransactionAt->isAfter('last month') &&
            $member['lastTransactionAmount'] > $monthlyContribution
        ) {
            $monthlyContribution = (float) $member['lastTransactionAmount'];
        }

        $yearlyContribution = (float) ($member['totalAmountDonated'] / max(1, $createdAt->floatDiffInYears()));
        $status = null;

        if ($monthlyContribution > 29) {
            $status = 'sponsor';
        } elseif ($monthlyContribution > 3 || $yearlyContribution > 20) {
            $status = 'backer';
        } elseif ($member['totalAmountDonated'] > 0) {
            $status = 'helper';
        }

        return array_merge($member, [
            'star' => ($monthlyContribution > 98 || $yearlyContribution > 500),
            'status' => $status,
            'monthlyContribution' => $monthlyContribution,
            'yearlyContribution' => $yearlyContribution,
        ]);
    }, json_decode(file_get_contents('https://opencollective.com/carbon/members/all.json'), true));
}

function getOpenCollective(string $status): string
{
    static $content = [];
    static $members = null;

    $members = $members ?? getAllBackers();

    if (!isset($content[$status])) {
        $list = array_filter($members, static function ($item) use ($status) {
            return ($item['status'] ?? null) === $status;
        });

        usort($list, static function (array $a, array $b) {
            return ($b['monthlyContribution'] <=> $a['monthlyContribution'])
                ?: ($b['totalAmountDonated'] <=> $a['totalAmountDonated']);
        });

        $content[$status] = implode('', array_map(static function (array $member) use ($status) {
            $href = htmlspecialchars($member['website'] ?? $member['profile']);
            $src = $member['image'] ?? (strtr($member['profile'], ['https://opencollective.com/' => 'https://images.opencollective.com/']).'/avatar/256.png');
            [$x, $y] = @getimagesize($src) ?: [0, 0];
            $validImage = ($x && $y);
            $src = $validImage ? htmlspecialchars($src) : 'https://opencollective.com/static/images/default-guest-logo.svg';
            $height = $status === 'helper' ? 32 : 64;
            $width = $validImage ? round($x * $height / $y) : $height;
            $href .= (strpos($href, '?') === false ? '?' : '&amp;').'utm_source=opencollective&amp;utm_medium=github&amp;utm_campaign=Carbon';
            $title = htmlspecialchars(($member['description'] ?? null) ?: $member['name']);
            $alt = htmlspecialchars($member['name']);

            return "\n".'        <a style="position: relative; margin: 10px; display: inline-block; border: '.($height / 8).'px solid '.($member['star'] ? '#7ac35f' : 'transparent').';'.($status === 'sponsor' ? ' background: white;' : ' border-radius: 50%; overflow: hidden;').'" title="'.$title.'" href="'.$href.'" target="_blank" rel="sponsored">'.
                '<img alt="'.$alt.'" src="'.$src.'" width="'.$width.'" height="'.$height.'">'.
                ($member['star'] ? '<span style="position: absolute; top: -15px; right: -15px; text-shadow: 0 0 3px black;">⭐</span>' : '').
                '</a>';
        }, $list))."\n    ";
    }

    return $content[$status];
}

function genHtml($page, $out, $jumbotron = '')
{
    global $template;

    $menu = '';
    $page = preg_replace_callback('/<nav>([\s\S]*?)<\/nav>/', function ($match) use (&$menu) {
        $menu = $match[1];

        return '';
    }, $page);
    $scripts = '';
    $page = preg_replace_callback('/<script>([\s\S]*?)<\/script>/', function ($match) use (&$scripts) {
        $scripts = $match[0];

        return '';
    }, $page);
    $pageWidth = empty($menu) ? 12 : 9;
    $page = preg_replace_callback('/<page-width>([\s\S]*?)<\/page-width>/', function ($match) use (&$pageWidth) {
        $pageWidth = $match[1];

        return '';
    }, $page);
    $html = $template;
    $html = str_replace('#{page}', $page, $html);
    $html = str_replace('#{pageWidth}', $pageWidth, $html);
    $html = str_replace('#{jumbotron}', $jumbotron, $html);
    $html = str_replace('#{menu}', $menu, $html);
    $html = str_replace('#{scripts}', $scripts, $html);
    $html = str_replace('#{openCollectiveSponsors}', getOpenCollective('sponsor'), $html);
    $html = str_replace('#{openCollectiveBackers}', getOpenCollective('backer'), $html);
    $html = str_replace('#{openCollectiveHelpers}', getOpenCollective('helper'), $html);

    file_put_contents($out, $html);

    echo "$out generated\n";
}

function evaluateCode(&$__state, $__code)
{
    ob_start();
    $result = call_user_func(function () use (&$__state, $__code) {
        foreach ($__state as $__key => &$__value) {
            $$__key = &$__value;
        }
        unset($__key);
        unset($__value);
        try {
            $lastResult = eval(strtr($__code, [
                'var_dump' => 'carbonDocVarDump',
            ]));
        } catch (Throwable $e) {
            echo "$__code\n\n";

            throw $e;
        }
        foreach (get_defined_vars() as $__key => $__value) {
            $__state[$__key] = &$$__key;
        }
        unset($__state['__state']);
        unset($__state['__code']);

        return $lastResult;
    });
    $ob = ob_get_clean();

    if ($result === false) {
        echo 'Failed lint check.'.PHP_EOL.PHP_EOL;
        $error = error_get_last();

        if ($error != null) {
            echo $error['message'].' on line '.$error['line'].PHP_EOL.PHP_EOL;
        }

        echo "---- eval'd source ---- ".PHP_EOL.PHP_EOL;
        $i = 1;

        foreach (preg_split("/$[\n\r]^/m", $__code) as $ln) {
            printf('%3s : %s%s', $i++, $ln, PHP_EOL);
        }

        exit(1);
    }

    return $ob;
}

function compile($src, $dest = null)
{
    static $imports = null;

    $code = file_get_contents($src);

    if (is_null($imports)) {
        $imports = implode('', array_map(function ($import) {
            return "use $import; ";
        }, [
            Carbon::class,
            CarbonImmutable::class,
            CarbonInterface::class,
            CarbonInterval::class,
            CarbonTimeZone::class,
            CarbonPeriod::class,
            Factory::class,
            FactoryImmutable::class,
            Translator::class,
            Carbonite::class,
        ]));
    }

    $__state = [];
    $lastCode = '';
    $codes = [];
    $oldCode = null;

    while ($oldCode !== $code) {
        $namesCache = [];
        $oldCode = $code;
        $code = preg_replace_callback('@{{(\w*)::each\((.+)\)}}([\s\S]+){{::endEach}}@isU', function ($matches) use ($imports, &$__state) {
            [, $name, $items, $contents] = $matches;

            return evaluateCode($__state, "$imports foreach ($items as \$item) { ?>{{eval(\$$name = <?php var_export(\$item); ?>;)}}$contents<?php }");
        }, $code);
        $code = preg_replace_callback('@{{(?:(\w*)::(\w+)\((.+)\)|((?:(\w+)_)?eval(?:\((.+)\))?))}}@sU', function ($matches) use ($imports, &$lastCode, &$codes, &$namesCache, &$__state) {
            [$orig, $name, $cmd, $src, $eval, $evalName, $evalContent] = array_pad($matches, 7, null);

            $src = trim($src, "\n\r");
            $code = empty($evalContent) ? $src : $evalContent;
            $code = trim($code, "\n\r");

            if (strlen($name) > 0) {
                if (in_array($name, $namesCache)) {
                    echo "$name cmd name used twice !!";
                    exit(1);
                }

                $namesCache[] = $name;
            }

            $ob = evaluateCode($__state, $imports.$code);

            // remove the extra newline from a var_dump
            if (strpos($src, 'var_dump(') === 0) {
                $ob = trim($ob);
            }

            if (!empty($eval)) {
                if (!empty($evalContent)) {
                    return $ob;
                }

                return empty($evalName) ? $lastCode : (isset($codes[$evalName]) ? $codes[$evalName] : $orig);
            }

            // Add any necessary padding to lineup comments
            if (preg_match('@/\*pad\(([0-9]+)\)\*/@', $src, $matches)) {
                $src = preg_replace('@/\*pad\(([0-9]+)\)\*/@', '', $src);
                $src = str_pad($src, intval($matches[1]));
            }

            // Inject the eval'd result
            if ($cmd == 'exec') {
                $codes[$name] = $ob;
                $lastCode = $ob;
            }

            return $src;
        }, $code);
    }

    // allow for escaping a command
    $code = strtr(trim(str_replace('\{\{', '{{', $code))."\n", [
        'carbonDocVarDump' => 'var_dump',
    ]);

    return $dest ? file_put_contents($dest, $code) : $code;
}

file_put_contents('languages.json', json_encode(Language::all(), JSON_PRETTY_PRINT));

$languages = array_map(function ($code) {
    $lang = new Language($code);

    return array_merge($lang->getNames(), [
        'id'         => $lang->getId(),
        'code'       => $lang->getCode(),
        'region'     => $lang->getRegion(),
        'regionName' => $lang->getRegionName(),
        'variant'    => $lang->getVariantName(),
    ]);
}, Carbon::getAvailableLocales());

file_put_contents('contribute/translate/assets/languages.json', json_encode($languages, JSON_PRETTY_PRINT));

$translations = [
    ['CarbonInterval::years(2)', '->forHumans()'],
    ['CarbonInterval::years(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::year()', '->forHumans()'],
    ['CarbonInterval::year()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::months(2)', '->forHumans()'],
    ['CarbonInterval::months(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::month()', '->forHumans()'],
    ['CarbonInterval::month()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::weeks(2)', '->forHumans()'],
    ['CarbonInterval::weeks(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::week()', '->forHumans()'],
    ['CarbonInterval::week()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::days(2)', '->forHumans()'],
    ['CarbonInterval::days(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::day()', '->forHumans()'],
    ['CarbonInterval::day()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::hours(2)', '->forHumans()'],
    ['CarbonInterval::hours(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::hour()', '->forHumans()'],
    ['CarbonInterval::hour()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::minutes(2)', '->forHumans()'],
    ['CarbonInterval::minutes(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::minute()', '->forHumans()'],
    ['CarbonInterval::minute()', '->forHumans(["aUnit" => true])'],
    ['CarbonInterval::seconds(2)', '->forHumans()'],
    ['CarbonInterval::seconds(2)', '->forHumans(["short" => true])'],
    ['CarbonInterval::second()', '->forHumans()'],
    ['CarbonInterval::second()', '->forHumans(["aUnit" => true])'],
    ['Carbon::parse("monday")', '->dayName'],
    ['Carbon::parse("monday")', '->shortDayName'],
    ['Carbon::parse("monday")', '->minDayName'],
    ['Carbon::parse("tuesday")', '->dayName'],
    ['Carbon::parse("tuesday")', '->shortDayName'],
    ['Carbon::parse("tuesday")', '->minDayName'],
    ['Carbon::parse("wednesday")', '->dayName'],
    ['Carbon::parse("wednesday")', '->shortDayName'],
    ['Carbon::parse("wednesday")', '->minDayName'],
    ['Carbon::parse("thursday")', '->dayName'],
    ['Carbon::parse("thursday")', '->shortDayName'],
    ['Carbon::parse("thursday")', '->minDayName'],
    ['Carbon::parse("friday")', '->dayName'],
    ['Carbon::parse("friday")', '->shortDayName'],
    ['Carbon::parse("friday")', '->minDayName'],
    ['Carbon::parse("saturday")', '->dayName'],
    ['Carbon::parse("saturday")', '->shortDayName'],
    ['Carbon::parse("saturday")', '->minDayName'],
    ['Carbon::parse("sunday")', '->dayName'],
    ['Carbon::parse("sunday")', '->shortDayName'],
    ['Carbon::parse("sunday")', '->minDayName'],
    ['Carbon::parse("january 2023")', '->monthName'],
    ['Carbon::parse("january 2023")', '->shortMonthName'],
    ['Carbon::parse("february 2023")', '->monthName'],
    ['Carbon::parse("february 2023")', '->shortMonthName'],
    ['Carbon::parse("march 2023")', '->monthName'],
    ['Carbon::parse("march 2023")', '->shortMonthName'],
    ['Carbon::parse("april 2023")', '->monthName'],
    ['Carbon::parse("april 2023")', '->shortMonthName'],
    ['Carbon::parse("may 2023")', '->monthName'],
    ['Carbon::parse("may 2023")', '->shortMonthName'],
    ['Carbon::parse("june 2023")', '->monthName'],
    ['Carbon::parse("june 2023")', '->shortMonthName'],
    ['Carbon::parse("july 2023")', '->monthName'],
    ['Carbon::parse("july 2023")', '->shortMonthName'],
    ['Carbon::parse("august 2023")', '->monthName'],
    ['Carbon::parse("august 2023")', '->shortMonthName'],
    ['Carbon::parse("september 2023")', '->monthName'],
    ['Carbon::parse("september 2023")', '->shortMonthName'],
    ['Carbon::parse("october 2023")', '->monthName'],
    ['Carbon::parse("october 2023")', '->shortMonthName'],
    ['Carbon::parse("november 2023")', '->monthName'],
    ['Carbon::parse("november 2023")', '->shortMonthName'],
    ['Carbon::parse("december 2023")', '->monthName'],
    ['Carbon::parse("december 2023")', '->shortMonthName'],
    ['Carbon::now()->subHours(2)', '->diffForHumans()'],
    ['Carbon::now()->addHours(2)->addMinute()', '->diffForHumans(["part" => 1])'],
    ['($d = Carbon::now())->copy()->subHours(2)', '->diffForHumans($d)'],
    ['($d = Carbon::now())->copy()->addHours(2)->addMinute()', '->diffForHumans($d)']
];

foreach ($languages as $language) {
    $locale = $language['id'];
    $output = [];

    foreach ($translations as [$start, $end]) {
        $output[$start.$end] = eval("use Carbon\Carbon; use Carbon\CarbonInterval; return {$start}->locale('{$locale}'){$end};");
    }

    file_put_contents(
        'contribute/translate/assets/translations/'.$locale.'.json',
        str_replace("\r", '', json_encode($output, JSON_PRETTY_PRINT))
    );
}

$directories = [
    '',
    'docs/',
    'laravel/',
    'symfony/',
    'contribute/translators/',
];

foreach ($directories as $directory) {
    genHtml(
        file_get_contents($directory.'index.src.html'),
        $directory.'index.o.html',
        $directory === '' ? compile('jumbotron.src.html') : ''
    );
}

genHtml(file_get_contents('history/index.src.html'), 'history/index.html');
genHtml(file_get_contents('contribute/index.src.html'), 'contribute/index.html');
genHtml(file_get_contents('contribute/translate/index.src.html'), 'contribute/translate/index.html');
genHtml(file_get_contents('contribute/docs/index.src.html'), 'contribute/docs/index.html');

if (isHistoryUpToDate()) {
    compile('reference/index.src.html', 'reference/index.html');
} else {
    echo "Your history.json is not up to date. To update the API reference, run:\nphp tools/api-history.php\n";
}

CarbonInterval::setLocale('en');

foreach ($directories as $directory) {
    compile($directory.'index.o.html', $directory.'index.html');
    unlink($directory.'index.o.html');
}
