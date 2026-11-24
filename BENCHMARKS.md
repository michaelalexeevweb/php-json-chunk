# Benchmarks

Six libraries and plain `json_decode()`, over the same documents, from ten thousand records to five
million. One machine, 2026-11-23, PHP 8.4, opcache off, version 2.5.0, median of 2 runs (`json_decode()`:
of 3), load around four to five. Yours will differ — what carries over is the **shape** of each line,
not the milliseconds.

**Which version.** The tables were taken with 2.5.0. The previous ones, taken with 1.12.0 on 2026-11-22
on a busier machine, put this library at 54 ms for a hundred thousand records and `json_decode()` at
42 ms; this run puts them at 47 ms and 30 ms. This library is about 15 % faster since — 2.4.0 hands values
on without a generator in between — and `json_decode()` was slowed then by the load, most of all at five
million records, where it needs 3.5 GB: 2.95 s then, 1.52 s now. What changed is listed in the
[changelog](CHANGELOG.md).

Reproducible from the repository:

```bash
php bin/benchmark.php --runs=2 --sizes=10000,50000,100000,500000,1000000,5000000
# every BlockSize of this library, five runs each
php bin/benchmark.php --runs=5 --sizes=10000,50000,100000,500000,1000000,5000000 --block-sizes --parsers=PhpJsonChunk
```

Back to the [README](README.md).

## The question worth asking first

**Does the file fit in memory?** If it does, `json_decode()` is the simplest thing to use, and the
faster: this library takes 1.5 to 1.6 times as long at every size measured, from ten thousand records
to five million. Streaming is paid for in time — half as long again — and in nothing else.

`json_decode()` on the whole file, same documents:

| Records | File | Time | Peak memory | Under a 512 MB limit |
|---:|---:|---:|---:|:--|
| 10k | 0.8 MB | 3.0 ms | 7.1 MB | fine |
| 50k | 3.9 MB | 15.5 ms | 35.1 MB | fine |
| 100k | 7.9 MB | 29.7 ms | 70.2 MB | fine |
| 500k | 39.9 MB | 149 ms | 349.3 MB | fine |
| 1M | 80.0 MB | 297 ms | 698.8 MB | **out of memory** |
| 5M | 404.3 MB | 1 522 ms | **3 545.9 MB** | **out of memory** |

It needs about **9× the size of the file**, every time — between 8.7× and 8.9× at every size
measured — and that ratio does not improve. Give PHP 4 GB and it reads the 404 MB document in a second
and a half. Give it the 512 MB that many deployments run with, and it stops at a file of roughly 58 MB.

`PhpJsonChunk` reads that same 404 MB document at **0.16 MB**, in 2.28 s.

## Memory: flat against climbing

```mermaid
xychart-beta
    title "Peak memory (MB): json_decode against streaming"
    x-axis "records" [10k, 50k, 100k, 500k, 1M, 5M]
    y-axis "peak MB" 0 --> 3600
    line "json_decode()" [7.1, 35.1, 70.2, 349.3, 698.8, 3545.9]
    line "PhpJsonChunk" [0.16, 0.16, 0.16, 0.16, 0.16, 0.16]
```

The flat line along the bottom is the whole product. It is 0.16 MB at ten thousand records and
0.16 MB at five million, because memory follows the biggest single element — and the 4 KB run of
elements decoded at once — never the length of the file. Every streaming reader in this comparison has
a flat line here; they differ only in how low it sits — from 0.01 MB to 0.31 MB — and all of them are
invisible against `json_decode()`.

## Time: every line is straight, the slope is what differs

```mermaid
xychart-beta
    title "Time (seconds) by record count"
    x-axis "records" [10k, 50k, 100k, 500k, 1M, 5M]
    y-axis "seconds" 0 --> 370
    line "json_decode()" [0.003, 0.02, 0.03, 0.15, 0.30, 1.52]
    line "PhpJsonChunk" [0.005, 0.02, 0.05, 0.24, 0.45, 2.28]
    line "JsonMachine" [0.09, 0.46, 0.96, 4.66, 9.24, 46.13]
    line "crocodile2u" [0.11, 0.56, 1.17, 5.48, 11.64, 55.21]
    line "Salsify" [0.30, 1.54, 2.98, 14.73, 29.63, 150.97]
    line "JsonCollectionParser" [0.30, 1.52, 3.06, 15.32, 30.46, 153.43]
    line "JsonDecodeStream" [0.72, 3.63, 7.26, 35.95, 71.94, 360.74]
```

Nothing here degrades as the file grows — every line is straight, so a document ten times bigger takes
ten times longer and no worse. What separates them is the constant, and the spread is wide: at five
million records the range is 2.3 s to 361 s. `PhpJsonChunk` and `json_decode()` lie on top of each other
along the bottom.

`PhpJsonChunk` reads **under half a millisecond per thousand records** at every size measured:

| Records | 10k | 50k | 100k | 500k | 1M | 5M |
|---|---:|---:|---:|---:|---:|---:|
| ms per 1 000 records | 0.47 | 0.46 | 0.47 | 0.49 | 0.45 | 0.46 |

## The numbers

**Every number is one complete read**: open the file, walk every record to the end, hand each one back
as a PHP value. Not a chunk, not a sample, and no `chunkSize` — the loop is item by item. So the
5 000 000 row is five million records read, and 2 280 ms over five million records is **0.46 µs
per record**.

Time in milliseconds, with the wall-clock reading beneath it once milliseconds stop being legible.
`json_decode()` is in the table because it is what everyone actually compares against — it is not a
streaming reader, and its column is there to be beaten on memory.

| Records | `json_decode()` | PhpJsonChunk | JsonMachine | crocodile2u | Salsify¹ | JsonCollectionParser | JsonDecodeStream |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 10k | *3.0* | **4.7** | 93.7 | 111.5 | 296.6 | 302.0 | 717.9 |
| 50k | *15.5* | **23.2** | 464.7 | 560.7 | 1 543.0 | 1 521.7 | 3 626.0 |
| 100k | *29.7* | **46.8** | 962.9 | 1 168.7 | 2 978.1 | 3 055.1 | 7 257.2 |
| 500k | *149.1* | **243.8** | 4 660.2 | 5 482.2 | 14 725.2<br><sub>15 s</sub> | 15 322.1<br><sub>15 s</sub> | 35 950.6<br><sub>36 s</sub> |
| 1M | *297.4* | **451.6** | 9 240.7 | 11 638.6<br><sub>12 s</sub> | 29 628.9<br><sub>30 s</sub> | 30 458.2<br><sub>30 s</sub> | 71 938.6<br><sub>1m 12s</sub> |
| 5M | *1 521.9*<br><sub>1.5 s</sub> | **2 280.1**<br><sub>**2.3 s**</sub> | 46 129.9<br><sub>46 s</sub> | 55 207.3<br><sub>55 s</sub> | 150 974.0<br><sub>2m 31s</sub> | 153 428.2<br><sub>2m 33s</sub> | 360 740.9<br><sub>6m 1s</sub> |
| **peak MB** | **7.1 → 3 546** | 0.16 | 0.31 | **0.01** | 0.03–0.04 | 0.03–0.04 | 0.04 |

Five million records is where the spread stops being an abstraction: two seconds against six minutes,
over the same 404 MB file.

**Read the last row across, not down.** Every streaming reader holds the same memory at ten thousand
records as at five million — two of them wander by a hundredth of a megabyte between sizes, which is
rounding, not growth. `json_decode()` has an arrow instead of a number, because its memory *is* the
file: close to 9× of it, every time. That is the only difference in this table that changes what is
possible, rather than what is quick.

`json_decode()` is faster than this library at every size — 1.5 to 1.6 times. It stops running at a
file of roughly 58 MB under a 512 MB limit, and that is the whole reason the rest of this table exists.

Among the streaming readers, `PhpJsonChunk` is the fastest at every size — **19 to 21 times** quicker
than JsonMachine, the next one along: 20× at ten thousand records, 21× at a hundred thousand, 20× at
five million.

It is **not** the thinnest: `crocodile2u` holds a sixteenth of the memory and three others hold about a
quarter of it. What 0.16 MB buys is a decoded PHP value per item and a key path to reach it; a parser
that hands you events rather than values has less to keep.

<sub>¹ `salsify` is a SAX parser and is not doing the same work: the listener in the benchmark counts
elements without ever building one, so its time is a floor rather than a like-for-like measurement.
Every other column hands back a PHP value for each element, and the benchmark iterates all of them.</sub>

## Every reader and every block size

The same documents again, with this library at each of its [block sizes](docs/reading.md#block-size-memory-against-speed)
— time in milliseconds. Its nine rows come from their own run, five rounds each; the default read
100 000 records in 44.6 ms there and in 46.8 ms in the run above, so the rows sit together fairly.

| Reader | 10k | 50k | 100k | 500k | 1M | 5M | peak MB |
|---|---:|---:|---:|---:|---:|---:|---:|
| `json_decode()` | *3.0* | *15.5* | *29.7* | *149.1* | *297.4* | *1 522* | 7.1 → 3 546 |
| **PhpJsonChunk `Auto`** (default) | 4.5 | 21.9 | 44.6 | 221.9 | 440.0 | 2 269 | 0.16 |
| PhpJsonChunk `Kb8` | 5.4 | 25.8 | 53.5 | 265.8 | 533.7 | 2 789 | **0.11** |
| PhpJsonChunk `Kb16` | 5.0 | 23.9 | 48.1 | 241.4 | 485.7 | 2 478 | 0.11 |
| PhpJsonChunk `Kb32` | 4.7 | 23.3 | 45.3 | 230.0 | 456.5 | 2 355 | 0.13 |
| PhpJsonChunk `Kb64` | 4.5 | 21.8 | 44.6 | 218.1 | 444.7 | 2 301 | 0.16 |
| PhpJsonChunk `Kb128` | 4.5 | 21.6 | 43.2 | 220.3 | 439.1 | 2 271 | 0.27–0.29 |
| PhpJsonChunk `Kb256` | 4.4 | 22.1 | 43.5 | 217.4 | 434.8 | 2 290 | 0.52–0.55 |
| PhpJsonChunk `Kb512` | 4.5 | 21.8 | 44.0 | 214.3 | 429.8 | 2 285 | 1.02–1.04 |
| PhpJsonChunk `Mb1` | 4.5 | 22.2 | 43.3 | 213.8 | 432.7 | 2 241 | 1.10–2.05 |
| JsonMachine | 93.7 | 464.7 | 962.9 | 4 660 | 9 241 | 46 130 | 0.31 |
| crocodile2u | 111.5 | 560.7 | 1 169 | 5 482 | 11 639 | 55 207 | 0.01 |
| Salsify¹ | 296.6 | 1 543 | 2 978 | 14 725 | 29 629 | 150 974 | 0.03–0.04 |
| JsonCollectionParser | 302.0 | 1 522 | 3 055 | 15 322 | 30 458 | 153 428 | 0.03–0.04 |
| JsonDecodeStream | 717.9 | 3 626 | 7 257 | 35 951 | 71 939 | 360 741 | 0.04 |

On records this small the block hardly moves the time — `Kb8` is about 20% behind, and everything
from 32 KB up is within a few percent — while memory goes from 0.11 MB to 2 MB. `Auto` stays at 64 KB on
such records and reads like `Kb64`. Where the block decides the time is large elements;
[measured there](docs/reading.md#block-size-memory-against-speed) with `php bin/benchmark-blocks.php`.

## An index, and workers side by side

Reproducible with:

```bash
php bin/benchmark-index.php --workers=1,2,4,6,8,12,16
```

The same five million records, measured with 2.5.0 on a quiet machine (2026-11-23, load around five,
twelve cores: six performance, six efficiency):

| | time |
|---|---:|
| building the index — 4 929 checkpoints, saved as 94 KB | 2.16 s |
| `getNth(4_999_999)`, no index | 2.34 s |
| `readFrom($index, 4_999_999, 1)` | 0.0002 s |

One pass with every record encoded and hashed — light work, as any real handler's is at the least —
through `forEachInParallel()`, a call per record, and `readInParallel()`, a call per part:

| workers | `forEachInParallel()` | | `readInParallel()` | |
|---:|---:|---:|---:|---:|
| none (a plain read) | 4.09 s | | 4.09 s | |
| 1 | 4.91 s | 0.8× | 4.63 s | 0.9× |
| 2 | 2.53 s | 1.6× | 2.37 s | 1.7× |
| 4 | 1.28 s | 3.2× | 1.21 s | 3.4× |
| 6 | 0.87 s | 4.7× | 0.82 s | 5.0× |
| 8 | 0.74 s | 5.5× | 0.70 s | 5.9× |
| 12 | 0.61 s | 6.7× | 0.57 s | 7.1× |
| 16 | 0.60 s | 6.8× | 0.57 s | 7.1× |

It grows with the workers up to the cores and stops there. One worker is slower than a plain loop — a
fork, and with `forEachInParallel()` a call per record. A handler that waits on a database or the
network gains from more workers than cores; this one does not wait. The plain read is faster than it
was in 2.3.0 — 4.54 s then — since 2.4.0 hands values on without a generator in between, so the
multiples here are a little smaller and the times a little better.

### The same records as NDJSON

`indexValues()`, in the same run: the five million records one to a line, 404 MB.

| | time |
|---|---:|
| building the index — 5 000 checkpoints | 2.32 s |
| `readValues($path, 1, 4_999_999)`, no index | 2.43 s |
| `readFrom($index, 4_999_999, 1)` | 0.0006 s |

| workers, `readInParallel()` | time | against a plain read |
|---:|---:|---:|
| none (a plain `readValues()`) | 4.19 s | |
| 1 | 4.51 s | 0.9× |
| 2 | 2.32 s | 1.8× |
| 4 | 1.17 s | 3.6× |
| 6 | 0.79 s | 5.3× |
| 8 | 0.67 s | 6.2× |
| 12 | 0.54 s | 7.8× |
| 16 | 0.55 s | 7.6× |

A checkpoint every 1 000 lines exactly: since 2.2.0 the checkpoints due inside a batch of lines are
found in it, where before they fell only where a batch started.

### Building an NDJSON index with workers

`indexValues($path, workers: N)`, the same five million lines (404 MB), best of two, 2.5.0, load
around five:

| workers | building the index | against one process |
|---:|---:|---:|
| 1 | 2.32 s | |
| 2 | 1.37 s | 1.7× |
| 4 | 0.70 s | 3.3× |
| 6 | 0.48 s | 4.8× |
| 8 | 0.40 s | 5.8× |
| 12 | 0.34 s | 6.8× |

### `readValues()`: one to a line, and pretty-printed

```bash
php bin/benchmark-values.php
```

| 200 000 documents (16 MB one to a line) | 2.0.0 | 2.1.0 | 2.5.0 |
|---|---:|---:|---:|
| one per line, `fgets()` + `json_decode()` | 88 ms | 84 ms | 85 ms |
| one per line, `readValues()` | 182 ms | 100 ms | 91 ms |
| pretty-printed one after another, `readValues()` | 205 ms | 180 ms | 184 ms |

2.0.0 measured from a copy of its source in the same session, load around fourteen; 2.1.0 and 2.5.0 on a
quiet machine. Within one run 2.0.0 and 2.1.0 stood at 182 and 104 ms. 2.3.1 took the lines of a block
straight on to the reader, without gathering them first, and a line cut by the end of a block the fast
way too: what is left between `readValues()` and the plain loop is the generator.

A run with the machine busy — a virtual machine taking two cores — gave 5.2× at six workers and *less* at
twelve than at eight: the workers had no cores to run on. Measure on a quiet machine.

## Method

- Dataset: synthetic root-array JSON, identical for every parser, four fields per record:
  `{"id":1,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}`.
- Metric: wall-clock time around the **whole** read — constructing the reader, opening the file, and
  iterating every record to the end — plus the peak memory delta over that span. Nothing is amortised
  and nothing is sampled.
- No chunking: every parser is asked for one item at a time, which is the shape they all share.
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
