<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\WrapperRunner;

use ParaTest\Tests\TestBase;
use ParaTest\WrapperRunner\WrapperRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;

use function dirname;
use function file_exists;
use function file_get_contents;
use function flock;
use function fopen;
use function touch;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const PHP_BINARY;

#[CoversClass(WrapperRunner::class)]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class CancellationTest extends TestBase
{
    #[TestWith([2])]
    #[TestWith([15])]
    public function testSignalToParentStopsIsolatedTest(int $signal): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'paratest',
            '--processes=1',
            '--no-configuration',
            '--tmp-dir=' . $this->tmpDir,
            $this->fixture('cancellation' . DIRECTORY_SEPARATOR . 'BlockedTest.php'),
        ], null, ['PARATEST_SIGNAL_TEST_DIR' => $this->tmpDir], null, 15);
        $process->start();

        try {
            while ($process->isRunning() && ! file_exists($this->tmpDir . DIRECTORY_SEPARATOR . 'ready')) {
                $process->checkTimeout();
                usleep(10000);
            }

            self::assertFileExists($this->tmpDir . DIRECTORY_SEPARATOR . 'ready', $process->getErrorOutput() . $process->getOutput());
            $process->signal($signal);
            while ($process->isRunning()) {
                $process->checkTimeout();
                usleep(10000);
            }

            self::assertSame(128 + $signal, $process->getExitCode());
            self::assertFileExists($this->tmpDir . DIRECTORY_SEPARATOR . 'signal');
            self::assertSame((string) $signal, file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'signal'));
            $lock = fopen($this->tmpDir . DIRECTORY_SEPARATOR . 'lock', 'r');
            self::assertIsResource($lock);
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'The isolated PHPUnit process is still running.');
        } finally {
            touch($this->tmpDir . DIRECTORY_SEPARATOR . 'release');
            $process->stop(0);
        }
    }
}
