<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests\Support;

use PhpJsonChunk\JsonChunkReader;
use RuntimeException;

final class JsonChunkPerformanceBenchmark
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function datasetSizes(): array
    {
        return [
            '10k' => [10_000],
            '30k' => [30_000],
            '50k' => [50_000],
            '100k' => [100_000],
        ];
    }

    /**
     * @return array{
     *     records: int,
     *     elapsedMs: float,
     *     peakDeltaMb: float,
     *     total: int,
     *     lastId: int|null,
     *     filePath: string,
     *     keptFile: bool
     * }
     */
    public function run(int $recordsCount, string|null $chunkTempDir = null): array
    {
        [$filePath, $shouldDeleteFile] = $this->createDatasetFile($recordsCount, $chunkTempDir);
        $reader = new JsonChunkReader();

        try {
            gc_collect_cycles();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $startUsage = memory_get_usage(false);
            $startTime = hrtime(true);

            $total = 0;
            $lastId = null;

            foreach ($reader->readGenerator($filePath, keyPath: 'data') as $item) {
                $total++;
                $lastId = $item['id'] ?? null;
            }

            $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
            $peakDeltaMb = (memory_get_peak_usage(false) - $startUsage) / 1024 / 1024;

            return [
                'records' => $recordsCount,
                'elapsedMs' => $elapsedMs,
                'peakDeltaMb' => max(0, $peakDeltaMb),
                'total' => $total,
                'lastId' => $lastId,
                'filePath' => $filePath,
                'keptFile' => !$shouldDeleteFile,
            ];
        } finally {
            if ($shouldDeleteFile) {
                $this->removeFileIfExists($filePath);
            }
        }
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function createDatasetFile(int $recordsCount, string|null $chunkTempDir): array
    {
        [$filePath, $shouldDeleteFile] = $this->resolveDatasetFilePath($recordsCount, $chunkTempDir);

        $directoryPath = dirname($filePath);

        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0777, true) && !is_dir($directoryPath)) {
            throw new RuntimeException(sprintf('Unable to create dataset directory "%s".', $directoryPath));
        }

        $handle = fopen($filePath, 'wb');

        if ($handle === false) {
            if ($shouldDeleteFile) {
                $this->removeFileIfExists($filePath);
            }

            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $filePath));
        }

        try {
            fwrite($handle, '{"count":' . $recordsCount . ',"data":[');

            for ($id = 1; $id <= $recordsCount; $id++) {
                if ($id > 1) {
                    fwrite($handle, ',');
                }

                fwrite(
                    $handle,
                    sprintf(
                        '{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}',
                        $id,
                    ),
                );
            }

            fwrite($handle, ']}');
        } finally {
            fclose($handle);
        }

        return [$filePath, $shouldDeleteFile];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveDatasetFilePath(int $recordsCount, string|null $chunkTempDir): array
    {
        if ($chunkTempDir !== null && $chunkTempDir !== '') {
            return [
                rtrim($chunkTempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sprintf('json_chunk_perf_%d.json', $recordsCount),
                false,
            ];
        }

        $filePath = tempnam(sys_get_temp_dir(), 'json_chunk_perf_');

        if ($filePath === false) {
            throw new RuntimeException('Unable to create temporary dataset file.');
        }

        return [$filePath, true];
    }

    private function removeFileIfExists(string $filePath): void
    {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }
}

