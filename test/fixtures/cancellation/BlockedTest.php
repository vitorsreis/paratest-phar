<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\cancellation;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function assert;
use function file_exists;
use function file_put_contents;
use function flock;
use function fopen;
use function getenv;
use function is_string;
use function pcntl_async_signals;
use function pcntl_signal;
use function time;
use function touch;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const SIGINT;
use const SIGTERM;

final class BlockedTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testWaitForCancellation(): void
    {
        $dir = getenv('PARATEST_SIGNAL_TEST_DIR');
        assert(is_string($dir));
        $lock = fopen($dir . DIRECTORY_SEPARATOR . 'lock', 'w');
        assert($lock !== false);
        self::assertTrue(flock($lock, LOCK_EX));

        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (int $signal) use ($dir): void {
                file_put_contents($dir . DIRECTORY_SEPARATOR . 'signal', (string) $signal);
            });
        }

        touch($dir . DIRECTORY_SEPARATOR . 'ready');
        $deadline = time() + 20;
        while (! file_exists($dir . DIRECTORY_SEPARATOR . 'release') && time() < $deadline) {
            usleep(10000);
        }
    }
}
