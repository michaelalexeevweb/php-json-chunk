<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\Tests\Support\JsonChunkPerformanceBenchmark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
final class JsonChunkReaderPerformanceTest extends TestCase
{
    #[DataProvider('datasetSizes')]
    public function testReadGeneratorPerformanceOnLargeNestedArray(int $recordsCount): void
    {
        $benchmark = new JsonChunkPerformanceBenchmark();
        $result = $benchmark->run($recordsCount);

        self::assertSame($recordsCount, $result['total']);
        self::assertSame($recordsCount, $result['lastId']);

        fwrite(
            STDERR,
            sprintf(
                "[performance] records=%d elapsed=%.2fms peakDelta=%.2fMB\n",
                $result['records'],
                $result['elapsedMs'],
                $result['peakDeltaMb'],
            ),
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function datasetSizes(): array
    {
        return JsonChunkPerformanceBenchmark::datasetSizes();
    }
}

