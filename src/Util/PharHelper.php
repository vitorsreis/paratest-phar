<?php

declare(strict_types=1);

namespace ParaTest\Util;

use Composer\Autoload\ClassLoader;
use LogicException;
use PHPUnit\Runner\Version;
use PHPUnit\Util\ExcludeList;
use ReflectionProperty;

use function array_diff_key;
use function array_filter;
use function assert;
use function class_exists;
use function constant;
use function define;
use function defined;
use function dirname;
use function fwrite;
use function getcwd;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function preg_match;
use function preg_quote;
use function realpath;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

use const ARRAY_FILTER_USE_KEY;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use const STDERR;

/** @internal */
final class PharHelper
{
    private static ?string $resolvedPhpunitBinary = null;
    private static ?string $phpunitArgument       = null;

    public static function getVersion(): string
    {
        return '@git_version@';
    }

    public static function getResolvedPhpunitBinary(): string
    {
        if (self::$resolvedPhpunitBinary === null || self::$resolvedPhpunitBinary === '') {
            throw new LogicException('The PHPUnit binary has not been resolved.');
        }

        return self::$resolvedPhpunitBinary;
    }

    public static function bootstrap(): void
    {
        /** @var array<int, string> $argv */
        $argv = $_SERVER['argv'];
        if (in_array('--status-file', $argv, true)) {
            require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit-wrapper.php';

            return;
        }

        self::preparePhpunitArgument($argv);
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        $_SERVER['argv'] = $argv;
    }

    public static function bootstrapCommand(string $cwd): void
    {
        if (self::$resolvedPhpunitBinary !== null) {
            return;
        }

        $phpunitBinary = self::phpunitResolver($cwd);
        if ($phpunitBinary === false) {
            self::fail();
        }

        self::paratestResolver($cwd);
        if (! class_exists(Version::class)) {
            self::fail();
        }

        self::$resolvedPhpunitBinary = $phpunitBinary;
    }

    /** @param array<int, string> $phpunitArgv */
    public static function bootstrapWorker(array $phpunitArgv): void
    {
        $cwd = getcwd();
        assert(is_string($cwd));
        $phpunitBinary = $phpunitArgv[0] ?? null;
        assert(is_string($phpunitBinary) && $phpunitBinary !== '');
        self::$resolvedPhpunitBinary = $phpunitBinary;

        if (str_ends_with(strtolower($phpunitBinary), '.phar')) {
            $phpunitPhar = realpath($phpunitBinary);
            assert($phpunitPhar !== false && is_file($phpunitPhar));
            require_once $phpunitPhar;
        }

        self::paratestResolver($cwd);
    }

    private static function phpunitResolver(string $cwd): string|false
    {
        if (self::$phpunitArgument !== null) {
            if (str_ends_with(strtolower(self::$phpunitArgument), '.phar')) {
                $phpunitPhar = realpath(self::$phpunitArgument);
                if ($phpunitPhar !== false && is_file($phpunitPhar)) {
                    require_once $phpunitPhar;

                    return $phpunitPhar;
                }

                return false;
            }

            return self::$phpunitArgument;
        }

        if (defined('__PHPUNIT_PHAR__') && is_string(__PHPUNIT_PHAR__) && is_file(__PHPUNIT_PHAR__)) {
            return __PHPUNIT_PHAR__;
        }

        if (defined('PHPUNIT_COMPOSER_INSTALL') && is_string(PHPUNIT_COMPOSER_INSTALL) && is_file(PHPUNIT_COMPOSER_INSTALL)) {
            return self::composerPhpunitBinary(PHPUNIT_COMPOSER_INSTALL);
        }

        $autoload = $cwd . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
                define('PHPUNIT_COMPOSER_INSTALL', $autoload);
            }

            return self::composerPhpunitBinary($autoload);
        }

        return false;
    }

    /** @param array<int, string> $argv */
    private static function preparePhpunitArgument(array &$argv): void
    {
        $phpunitKey = self::getArgvKeyFor($argv, 'phpunit');
        if ($phpunitKey === false) {
            return;
        }

        self::$phpunitArgument = $argv[$phpunitKey];
        foreach ($argv as $key => $argument) {
            if ($key >= $phpunitKey) {
                break;
            }

            if ($argument !== '-dpcov.enabled=1' && $argument !== '-dxdebug.mode=coverage') {
                continue;
            }

            unset($argv[$key]);
        }

        unset($argv[$phpunitKey]);
    }

    private static function composerPhpunitBinary(string $autoload): string|false
    {
        foreach (
            [
                dirname($autoload) . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR . 'phpunit',
                dirname($autoload) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit',
            ] as $path
        ) {
            $phpunit = realpath($path);
            if ($phpunit !== false && is_file($phpunit)) {
                return $phpunit;
            }
        }

        return false;
    }

    private static function fail(): never
    {
        fwrite(STDERR, 'ParaTest requires PHPUnit to be installed with Composer or provided as a PHAR.' . PHP_EOL);
        exit(1);
    }

    private static function paratestResolver(string $cwd): void
    {
        $projectAutoload = $cwd . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (defined('PHPUNIT_COMPOSER_INSTALL') && is_string(PHPUNIT_COMPOSER_INSTALL) && is_file(PHPUNIT_COMPOSER_INSTALL)) {
            $projectAutoload = PHPUNIT_COMPOSER_INSTALL;
        }

        if (! is_file($projectAutoload)) {
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

            return;
        }

        if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
            define('PHPUNIT_COMPOSER_INSTALL', $projectAutoload);
        }

        require_once $projectAutoload;

        $composerDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer';

        /** @var array<string, string> $classes */
        $classes         = require $composerDirectory . DIRECTORY_SEPARATOR . 'autoload_classmap.php';
        $paratestClasses = array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, 'ParaTest\\'),
            ARRAY_FILTER_USE_KEY,
        );

        $paratestLoader = new ClassLoader();
        $paratestLoader->addClassMap($paratestClasses);
        $paratestLoader->register(true);

        $fallbackLoader = new ClassLoader();
        $fallbackLoader->addClassMap(array_diff_key($classes, $paratestClasses));
        $fallbackLoader->register(false);

        /** @var array<string, string> $files */
        $files = require $composerDirectory . DIRECTORY_SEPARATOR . 'autoload_files.php';
        /** @var array<string, bool> $loadedFiles */
        $loadedFiles = $GLOBALS['__composer_autoload_files'] ?? [];
        foreach ($files as $identifier => $file) {
            if (isset($loadedFiles[$identifier])) {
                continue;
            }

            $loadedFiles[$identifier]             = true;
            $GLOBALS['__composer_autoload_files'] = $loadedFiles;
            require_once $file;
        }

        if (! class_exists(ExcludeList::class)) {
            return;
        }

        $paratestPhar = constant('__PARATEST_PHAR__');
        assert(is_string($paratestPhar) && $paratestPhar !== '');
        $directories   = new ReflectionProperty(ExcludeList::class, 'directories');
        $directoryList = $directories->getValue();
        assert(is_array($directoryList));
        $directories->setValue(null, [...$directoryList, $paratestPhar . '/']);
    }

    /** @param array<int, string> $argv */
    private static function getArgvKeyFor(array $argv, string $searchFor): int|false
    {
        $pattern = '~(?:^|[\\\\/])' . preg_quote($searchFor, '~') . '(?:[-_ ][^\\\\/]*)?(?:\\.phar)?$~Di';

        foreach ($argv as $key => $argument) {
            if (preg_match($pattern, $argument) === 1) {
                return $key;
            }
        }

        return false;
    }
}
