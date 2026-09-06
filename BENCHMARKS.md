# Benchmarks

Six libraries and plain `json_decode()`, over the same documents, from ten thousand records to five
million. One machine, 2026-09-06, PHP 8.4, opcache off, median of 2 runs. Yours will differ — what
carries over is the **shape** of each line, not the milliseconds.

Reproducible from the repository:

```bash
php bin/benchmark.php --runs=2 --sizes=10000,50000,100000,500000,1000000,5000000
```

Back to the [README](README.md).

## The question worth asking first

**Does the file fit in memory?** If it does, `json_decode()` is faster than every streaming reader
here — ten times faster at five million records — and you should use it. Streaming buys one thing:
memory that does not grow with the file. It is paid for in time.

`json_decode()` on the whole file, same documents:

| Records | File | Time | Peak memory | Under a 512 MB limit |
|---:|---:|---:|---:|:--|
| 10 000 | 1 MB | 6 ms | 7.2 MB | fine |
| 50 000 | 5 MB | 25 ms | 35.7 MB | fine |
| 100 000 | 10 MB | 49 ms | 71.5 MB | fine |
| 500 000 | 51 MB | 246 ms | 356.8 MB | fine |
| 1 000 000 | 103 MB | 496 ms | 714.0 MB | **out of memory** |
| 5 000 000 | 528 MB | 2 897 ms | **3 661.5 MB** | **out of memory** |

It needs about **6.9× the size of the file**, every time, and that ratio does not improve. Give PHP
4 GB and it will read the 528 MB document in under three seconds. Give it the 512 MB that many
deployments run with, and it stops at a file of roughly 70 MB.

`PhpJsonChunk` reads that same 528 MB document at **0.15 MB**, in 29.7 s.

## Memory: flat against climbing

```mermaid
xychart-beta
    title "Peak memory (MB): json_decode against streaming"
    x-axis "records" [10k, 50k, 100k, 500k, 1M, 5M]
    y-axis "peak MB" 0 --> 3700
    line "json_decode()" [7.2, 35.7, 71.5, 356.8, 714.0, 3661.5]
    line "PhpJsonChunk" [0.15, 0.15, 0.15, 0.15, 0.15, 0.15]
```

The flat line along the bottom is the whole product. It is 0.15 MB at ten thousand records and
0.15 MB at five million, because memory follows the biggest single element, never the length of the
file. Every streaming reader in this comparison has a flat line here; they differ only in how low it
sits — from 0.01 MB to 0.31 MB — and all of them are invisible against `json_decode()`.

## Time: every line is straight, the slope is what differs

```mermaid
xychart-beta
    title "Time (seconds) by record count"
    x-axis "records" [10k, 50k, 100k, 500k, 1M, 5M]
    y-axis "seconds" 0 --> 380
    line "PhpJsonChunk" [0.06, 0.29, 0.62, 2.95, 5.89, 29.70]
    line "JsonMachine" [0.09, 0.45, 0.94, 4.68, 9.50, 47.67]
    line "crocodile2u" [0.12, 0.54, 1.12, 5.63, 11.36, 58.07]
    line "Salsify" [0.32, 1.47, 2.98, 15.17, 30.46, 154.13]
    line "JsonCollectionParser" [0.30, 1.54, 3.05, 15.50, 31.31, 158.70]
    line "JsonDecodeStream" [0.75, 3.54, 7.25, 36.73, 73.52, 371.92]
```

Nothing here degrades as the file grows — every line is straight, so a document ten times bigger takes
ten times longer and no worse. What separates them is the constant, and the spread is wide: at five
million records the range is 30 s to 372 s.

`PhpJsonChunk` reads **5.9 ms per thousand records** at every size measured:

| Records | 10k | 50k | 100k | 500k | 1M | 5M |
|---|---:|---:|---:|---:|---:|---:|
| ms per 1 000 records | 5.71 | 5.79 | 6.16 | 5.90 | 5.89 | 5.94 |

## The numbers

Time in milliseconds, peak memory delta in MB.

| Records | PhpJsonChunk | JsonMachine | crocodile2u | Salsify¹ | JsonCollectionParser | JsonDecodeStream |
|---:|---:|---:|---:|---:|---:|---:|
| 10 000 | **57.1** | 91.7 | 118.5 | 316.1 | 297.1 | 752.1 |
| 50 000 | **289.6** | 453.6 | 542.7 | 1 473.6 | 1 536.9 | 3 544.1 |
| 100 000 | **615.7** | 936.7 | 1 118.9 | 2 981.9 | 3 047.5 | 7 251.8 |
| 500 000 | **2 950.7** | 4 684.3 | 5 628.1 | 15 168.3 | 15 502.5 | 36 726.6 |
| 1 000 000 | **5 889.2** | 9 495.9 | 11 356.2 | 30 462.4 | 31 307.9 | 73 515.0 |
| 5 000 000 | **29 700.4** | 47 667.1 | 58 070.8 | 154 132.4 | 158 697.6 | 371 922.3 |
| **peak MB** | 0.15 | 0.31 | **0.01** | 0.03 | 0.03 | 0.04 |

`PhpJsonChunk` is the fastest at every size, by about 1.6× over the next one. It is **not** the
thinnest: `crocodile2u` holds a fifteenth of the memory and three others hold a quarter of it. What
0.15 MB buys is a decoded PHP value per item and a key path to reach it; a parser that hands you
events rather than values has less to keep.

<sub>¹ `salsify` is a SAX parser and is not doing the same work: the listener in the benchmark counts
elements without ever building one, so its time is a floor rather than a like-for-like measurement.
Every other column hands back a PHP value for each element, and the benchmark iterates all of them.</sub>

## Method

- Dataset: synthetic root-array JSON, identical for every parser, five fields per record.
- Metric: wall-clock time around the loop, and peak memory delta.
- Each size is generated once and read by every parser in turn; run order is shuffled between runs.
- `json_decode()` is measured separately, one process per size, so a run that exhausts memory does not
  take the others with it. That is also how the 512 MB column was produced.

### What each parser is asked to do

Five of the six hand back a PHP value for every element, and the benchmark iterates all of them:
`PhpJsonChunk`, `JsonMachine`, `JsonDecodeStream`, `JsonCollectionParser` and
`crocodile2u/json-streamer`.

**`salsify/json-streaming-parser` is not doing the same work.** It is a SAX-style parser: it reports
events, and the benchmark's listener counts root-array elements without ever assembling one. Its
number is a floor — materialising the items would only add to it.

One difference that turned out not to matter: `JsonMachine` yields `stdClass` by default where the
others yield arrays. Re-measured with `ExtJsonDecoder(true)` so that it yields arrays too, 100k
records took 1 078 ms against 1 053 ms with objects — a 2% difference, which is noise at this scale
and not where the gap comes from.

## Compared against

- [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk)
- [`JsonMachine`](https://github.com/halaxa/json-machine)
- [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer)
- [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser)
- [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser)
- [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream)
