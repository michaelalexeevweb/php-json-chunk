# Benchmarks

Full benchmark results for `PhpJsonChunk` and comparable streaming libraries.

## Compared Repositories

- [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk)
- [`JsonMachine`](https://github.com/halaxa/json-machine)
- [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer)
- [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser)
- [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser)
- [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream)

## Methodology

- Dataset: synthetic root-array JSON, identical for all parsers.
- Metric: wall-clock time and peak memory delta.
- Ordering: tables are sorted by speed (faster -> slower).
- Runs:
  - 10k, 30k, 50k, 100k -> median of 3 runs
  - 500k, 1,000,000 -> median of 2 runs

## 10,000 Records (Median of 3 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 19.8 ms | 0.15 MB |
| 2 | JsonMachine | 34.4 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 38.1 ms | 0.01 MB |
| 4 | Salsify | 97.1 ms | 0.04 MB |
| 5 | JsonCollectionParser | 101.0 ms | 0.04 MB |
| 6 | JsonDecodeStream | 260.1 ms | 0.04 MB |

## 30,000 Records (Median of 3 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 56.6 ms | 0.15 MB |
| 2 | JsonMachine | 99.4 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 113.5 ms | 0.01 MB |
| 4 | Salsify | 295.0 ms | 0.04 MB |
| 5 | JsonCollectionParser | 303.9 ms | 0.04 MB |
| 6 | JsonDecodeStream | 790.2 ms | 0.04 MB |

## 50,000 Records (Median of 3 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 94.9 ms | 0.15 MB |
| 2 | JsonMachine | 165.4 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 190.4 ms | 0.01 MB |
| 4 | Salsify | 494.2 ms | 0.03 MB |
| 5 | JsonCollectionParser | 508.9 ms | 0.03 MB |
| 6 | JsonDecodeStream | 1292.6 ms | 0.04 MB |

## 100,000 Records (Median of 3 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 190.3 ms | 0.15 MB |
| 2 | JsonMachine | 330.0 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 383.2 ms | 0.01 MB |
| 4 | Salsify | 980.2 ms | 0.04 MB |
| 5 | JsonCollectionParser | 1025.8 ms | 0.04 MB |
| 6 | JsonDecodeStream | 2585.6 ms | 0.04 MB |

## 500,000 Records (Median of 2 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 1001.0 ms | 0.15 MB |
| 2 | JsonMachine | 1644.1 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 1966.9 ms | 0.01 MB |
| 4 | Salsify | 5074.0 ms | 0.03 MB |
| 5 | JsonCollectionParser | 5267.0 ms | 0.03 MB |
| 6 | JsonDecodeStream | 13483.7 ms | 0.04 MB |

## 1,000,000 Records (Median of 2 Runs)

| Rank | Parser | Time | Peak mem |
|---|---|---:|---:|
| 1 | PhpJsonChunk | 1893.0 ms | 0.15 MB |
| 2 | JsonMachine | 3294.3 ms | 0.31 MB |
| 3 | Crocodile2uJsonStreamer | 3854.8 ms | 0.01 MB |
| 4 | Salsify | 9829.5 ms | 0.03 MB |
| 5 | JsonCollectionParser | 10593.6 ms | 0.03 MB |
| 6 | JsonDecodeStream | 26499.0 ms | 0.04 MB |

## Reproduce

```bash
composer benchmark
```

```bash
php bin/benchmark.php --runs=5 --sizes=10000,50000,100000
```

> Benchmark results depend on hardware, PHP version, and OS. Prefer median values from multiple runs.

