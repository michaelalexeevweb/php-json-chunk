# Changelog

Every release from 1.2.1 on. Earlier ones — 1.0.0 to 1.1.2 — are described only in the
[GitHub releases](https://github.com/michaelalexeevweb/php-json-chunk/releases).

## 2.4.0 — 2026-11-23

### Added

- **`readRaw()` and `readValuesRaw()`: entries as the JSON they are written in.** To pass records on — to another file, a queue, a database's JSON column — without a `json_decode()` and a `json_encode()` of each, and with nothing lost: a large integer stays its digits, `1.10` stays `1.10`. Each entry is still checked — by `json_validate()` where PHP has it — and refused, or handed to `onInvalidItem`, as `readGenerator()` does. As fast as `readGenerator()`; a million records copied from a list to NDJSON 733 ms → 565 ms. Objects of a list are taken a run at a time: the run decoded once, which checks it and counts its elements, and cut at every `},{` with JSON's spaces around the comma — a cut inside a string or inside an element only adds a piece, so as many pieces as elements means each piece is one. Tested against `readGenerator()` and `readValues()` on random documents in three block sizes, compact and pretty-printed, and on JSONTestSuite: the same values, the same refusals with the same words; six mutants, all caught.
- **`validate()` and `validateValues()`: is it valid JSON?** A file checked as it streams: `null`, or a `ValidationError` with the reason and the byte, without an exception to catch. Strict whatever `onInvalidItem` says; an element over `maxElementBytes` is an error too; a root that is a string or a number is read whole — from a stream as well, its first block kept to be read again. Every root-array document of JSONTestSuite gets `json_decode()`'s verdict.
- **`Psr7Source`: a PSR-7 stream** — a Guzzle or Symfony response's body — read through its own `read()` and `eof()`, with no PSR package needed, and not closed. And the README shows a gzipped file read through PHP's zlib stream, which a test now holds to.

### Performance

- **`readGenerator()` 16 % faster, a wildcard path 7 %.** With no `limit`, `offset` or `chunkSize` there is nothing to cut, but every value still went through a generator that looked at it only to hand it on; it is now delegated. A million records: 530 ms → 445 ms; `*.id` 1 300 ms → 1 213 ms. Found while making `readRaw()` as fast as it.

## 2.3.1 — 2026-11-23

### Performance

- **NDJSON read 10 % faster, indexed 9 % faster.** Three costs found by measuring each against `fgets()` + `json_decode()` on a million lines: a line cut by the end of a 64 KB block went the slow way, a character at a time, after the fast ways had each been tried — one line every block, 6 % of the read; the block is now read onto the rest of the line instead. The lines of a block were decoded into an array and then handed on from it, a pass of its own, 7 %; they are now decoded as they are handed on. And the reader's options were read at every line, 5 %; once now. A million lines: 517 ms → 467 ms, against 410 ms for the plain loop — what is left is the generator, the same lines decoded without being handed on taking 420 ms. Indexing them 525 ms → 476 ms; pretty-printed documents unchanged. Measured old against new in turns, each in a process of its own.

### Fixed

Found in a review of 2.2.0 and 2.3.0; each has a test that fails on 2.3.0.

- **A read in parallel without pcntl says so, with one worker too.** In 2.3.0 the check for pcntl moved to where `indexValues()` shares it, and there it asks only when more than one worker is wanted; but `readInParallel()` and `forEachInParallel()` fork even one. Without pcntl — Windows, a web SAPI — `workers: 1`, or `Workers::Auto` on a machine of one core, ended in `Error: Call to undefined function pcntl_fork()` instead of the `RuntimeException` that says what is missing.
- **`Workers::cores()` finds a limit below the cgroup root.** Only the root's `cpu.max` was read, which is the container's own only inside a container with a cgroup namespace of its own; in one sharing the host's — `docker run --cgroupns=host --cpus=2` — or under a systemd slice with a CPU quota, the limit sits on the process's own group, and 2.3.0 gave the host's 12 cores where 2 were allowed. The process's group is read from `/proc/self/cgroup`, and the limits of it and every group above it are taken, the narrowest winning; cgroup v1's cpuset too.
- **An index saved with `every` below 1 is refused.** `extendIndex()` made no checkpoint with it, and failed on its own index with "no checkpoint for the first entry".
- **A worker that dies while indexing leaves the index to the one pass.** A worker of `indexValues($path, workers: N)` that could not be started, or died — out of memory — failed the whole index; it now falls back to the one pass, as anything else the workers cannot settle does. Not covered by a test: a worker cannot be made to die there reliably.

## 2.3.0 — 2026-11-23

### Added

- **NDJSON indexed by several processes.** `indexValues($path, workers: 8)` cuts a file of one document to a line into stretches at newlines, one for each worker; each counts its lines and notes where every `every`-th starts, and the counts are added up. Five million lines, 404 MB: 2.65 s in one process, 0.71 s with 4 workers, 0.37 s with 12. Every line is checked to be a whole document — with `json_validate()`, without decoding, where PHP has it — since a pretty-printed document has newlines inside and a line of it, `  1` of a list, can pass for a document of its own; a file with one line that is not, or with anything the one pass would refuse or hand over, is indexed in one pass after all, so the index, or the refusal, is the same as without workers. Files under 1 MB are indexed in one pass. Tested against the one-pass index on strict NDJSON with CRLF and blank lines, and on nine kinds of file it must fall back on; five mutants of the checks, all caught.

- **`Workers::Auto`: as many workers as there are cores.** `forEachInParallel()`, `readInParallel()` and `indexValues()` take it in place of a number. On Linux it is the fewest of the processors, the ones the process's cgroup lets it run on, and the cgroup's CPU quota rounded up — a container started with `--cpus=2` gets 2, where `nproc` there says the host's 12; with `--cpuset-cpus=0-2`, 3 — and on macOS what `sysctl hw.logicalcpu` says; 1 when it cannot be told. `Workers::cores()` says what it found. Asked for, never the default: reading and indexing stop getting faster at the cores, but a handler waiting on a database or the network gains from more, and each worker holds its own memory and connections. The reading of cgroup v1 and v2 files is tested on fourteen systems, from none to a quota narrower than the cpuset.

## 2.2.0 — 2026-11-23

### Added

- **`extendIndex()`: an index of a growing log, kept up.** A log or an export grows by what is appended to it, and a changed file made its index refuse to read — five million lines indexed again for the fifty thousand added. `extendIndex($index)` reads from the index's last checkpoint on, and only that: 0.03 s for 50 000 lines appended to five million, against 3.2 s to index them all again. It is refused for a file changed rather than appended to — shorter, or its last 4 KB before the old end not what they were — and for an index of a list or an object. An index keeps a digest of those bytes and its `every`, saved by `toJson()`; one saved before 2.2.0 cannot be extended and says so. `readFrom()` on a grown sequence suggests it.
- **`count()`, `getFirst()`, `getLast()` and `getNth()` take an index** in place of a path: the count from the index without reading, an entry from the nearest checkpoint, after checking that the file has not changed.

### Changed

- **A sequence indexed while it is being written.** `indexValues()` on a file that ends inside a document — a log whose writer is half-way through a line — failed with "unexpected end of input". That document is now left out, as one not finished yet: not counted, not handed to `onInvalidItem`, and reads from the index stop before it; `extendIndex()` reads it once it is whole. `readValues()` still refuses such a file, which it reads as it is.
- **An index of short lines has a checkpoint every `every` lines.** Lines are taken a block at a time, and a checkpoint fell only where a batch started: a 64 KB block of short lines left one where `every: 100` asked for hundreds. The checkpoints due inside a batch are now found in it, from the lengths of its lines; reading is as fast as before, indexing very short lines 4 % slower for the checkpoints it now makes.

### Tests

- The test of a read that fails without printing is skipped when the process runs as root, which writes where the permissions it relies on say no one may.

## 2.1.2 — 2026-11-23

### Fixed

Found in a review of everything since 1.19.0; each has a test that fails on 2.1.1.

- **A worker's fatal error reaches the caller.** A worker that died of a fatal error — out of memory — was reported as "ended without finishing", the reason left on its standard error. It now sends the error: `worker 1: PHP Fatal error: Allowed memory size … exhausted in …`. PHP still runs the caller's shutdown functions in it first, as on any fatal error; the README says so. A worker that ends without a word says how it ended — `ended without finishing (exit status 1)`, `(killed by signal 11)`. The PHP 8.1 of GitHub's runners runs no shutdown function after running out of memory, so there only how it ended comes through; PHP 8.1 built from the official image, and 8.2 to 8.4 everywhere, send the error.
- **Each read from an index hands over only its own invalid entries.** A read seeks to a checkpoint before its first entry and reads one entry past its last, and `onInvalidItem` was called for every invalid entry it passed: the parts of `partitions()` handed over the ones between them two or three times, in parallel once in each worker. Now only those within the read's own entries — for lists, objects and NDJSON alike. The README shows how to bring them back from the workers, where `onInvalidItem` runs.
- **An index keeps the file's whole path.** A relative path was kept as given, so a saved index read in another directory named another file, or none. `index()` and `indexValues()` keep `realpath()`; a path it cannot settle, a stream wrapper's, as given.
- **A damaged index is refused.** Checkpoints are looked up by binary search, which takes them to ascend; `fromJson()` and the constructor took them in any order, and one out of place would land a read at another entry's start and number every entry after it wrongly. Checkpoints out of order, at a negative byte, past the count, or none for the first entry, and a negative count, are refused.
- **A result that cannot be read back is that worker's failure.** A result whose class threw while being read back — or a notice from `unserialize()`, which a framework's error handler turns into an exception — escaped the wait for the workers: the ones not yet waited for were left unreaped, and the message named no worker. Now `worker 1: its result could not be read back — DomainException: …`.
- **The wait for a worker is taken up again after a signal.** When a signal of the caller's cut `pcntl_waitpid()` short, the worker was left a zombie. Not covered by a test: the signal cannot be made to land there reliably.

## 2.1.1 — 2026-11-23

### Fixed

- **A worker ran the caller's destructors, shutdown functions and output buffers.** A forked worker is a copy of the caller, and it ended with `exit()`, which ran all three in every worker: the destructor of every object the caller held, every shutdown function it had registered, and every output buffer it had open, printed. A PDO connection opened before `readInParallel()` or `forEachInParallel()` would have been closed by each worker's copy of it — on the very socket the caller goes on using — and anything the caller had buffered was printed once per worker. A worker now throws away the copied buffers when it starts and ends itself with SIGKILL once it has sent its result, so nothing of the caller's runs; finishing is told by the mark it sends, as before. Without PHP's posix extension it still ends with `exit()`. Found by a test with three workers: the destructor and the shutdown function ran four times, the buffered text was printed four times.

## 2.1.0 — 2026-11-23

### Performance

- **NDJSON read 1.75× faster.** `readValues()` takes documents one to a line a block at a time: the lines that end within 64 KB split at the newlines in C, each decoded by itself — never joined, so no line can make up for what another lacks — and taken only where a document starts and only when `json_decode()` takes the whole line. A line that is not a whole document — a pretty-printed one's `{`, two documents on one line, a broken one — goes the old way, with the old message at the old byte. 200 000 lines: 182 ms → 104 ms, against 88 ms for `fgets()` + `json_decode()`; pretty-printed documents 205 ms → 186 ms. `bin/benchmark-values.php` measures it.
  - The messages, bytes and numbers of broken lines were taken from 2.0.0 and pinned by tests; thousands of random documents of every kind with every separator read back as written, in blocks of three sizes.
  - Found while measuring: the first version split the whole block before trying the first line, which a pretty-printed file paid for at every document. The first line is now tried alone, without an exception.

### Added

- **An index of NDJSON.** `indexValues($path, $every = 1000)` indexes a sequence of documents — JSON Lines, NDJSON, documents pretty-printed one after another or after record separators, as `readValues()` reads them — so that `readFrom()` seeks to any document and `partitions()`, `readInParallel()` and `forEachInParallel()` split them between workers. Documents are numbered as `readValues()` numbers them, a refused one included, and `readFrom()`'s offset and limit count documents by those numbers. A saved index records its kind as `JsonIndex::VALUES`. Five million lines, 404 MB: the last one in 2.69 s without the index, 0.1 ms with it, the index built in 2.58 s; `readInParallel()` with 12 workers 0.65 s against 4.44 s for a plain `readValues()`. Tested against `readValues()` at every offset near a checkpoint, for lines, mixed and pretty-printed documents, record separators, documents with nothing between them and documents larger than a block; and forked, every line read once between four workers.

## 2.0.0 — 2026-11-23

### Added

- **Results from the workers.** `readInParallel($index, $workers, $handler)` forks as `forEachInParallel()` does, but calls the handler once in each worker, with a generator of its part's `key => entry` and its `Partition`, and returns what each returned, keyed by part in their order — a total, a count, a list of ids, whatever the parts are put together from. Before, a handler could only reach the caller through a file, a database or a queue. Results are sent back with `serialize()`, an object with its class — a DTO of the caller's own comes back a DTO, and the method's `@template` lets PHPStan type the list from the handler's return type; a result that cannot be sent — a closure — fails the read and names the worker. The README's example makes a DTO of each entry and returns one for each part. Five million records: as fast as `forEachInParallel()`, a few per cent faster with no call per record.

### Changed

- **Parts and checkpoints are objects.** `JsonIndex::partitions()` returns `Partition` objects — `number`, `offset`, `limit` — instead of `['offset' => …, 'limit' => …]` arrays, and `checkpoints()` and `checkpointBefore()` return `Checkpoint` objects — `entry`, `byte` — instead of `[entry, byte]` pairs, so an IDE offers the fields. Code that unpacked the arrays reads the properties now. The index keeps the pairs inside and saves them as before: an index saved by 1.19.0 or 1.20.0 loads unchanged. `readInParallel()`'s handler gets the `Partition` it reads.

### Fixed

- **A worker that exits halfway is a failure.** A handler calling `exit(0)` ended its worker as a finished one would, and `forEachInParallel()` took the part as done: the entries after it were never handled and nothing was said. A worker now sends a mark when it finishes its part; one that ends without it fails the read with "ended without finishing".

## 1.20.0 — 2026-11-23

### Added

- **Reading in parallel.** `JsonIndex::partitions($n)` cuts an indexed container into at most `$n` runs of consecutive entries, their sizes differing by one at most, each to be read with `readFrom()` by whatever runs your workers — queue jobs, separate commands. `forEachInParallel($index, $workers, $handler)` forks the workers itself, on PHP's command line on Linux or macOS, calls the handler with every entry and its key in them, and throws with what each worker failed with when any did. Five million records, each encoded and hashed: 4.54 s read plainly, 1.30 s with 4 workers, 0.88 s with 6, 0.62 s with 12 — the twelve cores here, six performance and six efficiency, are where it stops getting faster. `bin/benchmark-index.php` measures it.
  - The handler runs in the workers, so what it does must reach beyond the process, and a connection it uses is opened inside it rather than before the fork.
  - At most 256 workers (`JsonChunkReader::MAX_WORKERS`): not tied to the cores, since a handler waiting on a database or the network gains from more, but bounded — without it a mistyped number forked one process per entry, which the tests caught as a thousand processes for a thousand records. When a worker cannot be started, those already running are waited for before the exception.
  - The tests fork in a PHP process of their own, never in the test runner's; they check that the workers read every entry once between them, with its key, and that a worker's failure reaches the caller.

## 1.19.0 — 2026-11-23

### Added

- **An index for random access: `index()`, `readFrom()` and `JsonIndex`.** One pass over a container in a file remembers where its entries start — the first, then about every thousandth — and `readFrom($index, $offset, $limit)` seeks to the nearest checkpoint before the entry asked for instead of reading every entry before it. On five million records (404 MB), entry 4 999 000 takes 0.28 ms instead of 2.9 s; building the index takes 4 s, once, and it saves as 94 KB with `toJson()` and loads with `JsonIndex::fromJson()`, so one process can build it and others use it.
  - `readFrom()` gives what `readGenerator()` with the same key path, offset and limit gives, keys included — checked at offsets on, before and after checkpoints, in compact and pretty-printed files, for lists and objects keyed by id.
  - Checkpoints sit where the reader passes an entry's start; inside a run of entries decoded at once there is none, so they come at most a run apart from every `every` — one read covers at most about 1 040 entries.
  - An index is of one container in a file — not a stream, which cannot seek; no wildcard path. It keeps the file's size and modification time and refuses a file that has changed since, and checks that an entry still starts at the checkpoint it seeks to.

## 1.18.1 — 2026-11-23

A code review of `src`, its findings checked one by one; the four that mattered were in `readValues()`, new in 1.18.0.

### Fixed

- **`readValues()` no longer goes quadratic** when documents hold a bracket of their own kind inside a string. Finding a document's end counted brackets to the end of the block, and a failure there was not counted as a miss, so every document of such a stretch searched the block again: 20 000 short documents with `"["` in them took 3.2 s, and now take a few tens of milliseconds.
- **`readValues()` no longer repairs broken input.** A missing comma inside one document, read as a boundary, and a stray comma between two others made a run's count of documents come out right — `{"a":[{"x":1}{"y":2}]}{"b":1},{"c":1}{"d":1}` came back as four good documents, the first one fixed. A run is now decoded only when nothing but whitespace and record separators stands between its documents.
- **Only JSON's whitespace separates documents.** A run's boundary allowed a form feed or a vertical tab — PCRE's `\s` — which JSON does not, so the same bytes were read or refused depending on whether a run formed.
- **A number, `true`, `false` or `null` ends where the next document begins** — at a record separator, a bracket or a quote. `1` right before an RFC 7464 record separator was refused, and `1[2]` was read as `1[2`.
- **`readValues()` keys are the documents' numbers**, the same numbers a handler is given for a refused document. They counted only the documents handed out, and after a refused one the two disagreed.
- **A failed run keeps back only runs of its own kind** — a list's, an object's members', a sequence's documents'. A nested map's members that could not be read as a run kept the list around them from its runs too.
- **`composer test` runs under PHP's default 128 MB.** Two test files built their large elements out of tens of thousands of small arrays; the suite needed over 128 MB and died with a fatal error wherever the limit was the default — only CI, with no limit, passed. It now peaks at 38 MB, and the tests still fail when the behaviour they pin is broken.

### Documented

- `JsonPointer`: `-` is every element wherever it stands, so on an object it does not name a member called `-`.
- `StreamSource` needs a blocking stream; a non-blocking socket's pause reads as the end of the document.
- `JsonChunkReaderInterface` grows in minor releases, as it has with `readPaths()`, `getValue()` and `readValues()`; to change what a reader does, wrap `JsonChunkReader` rather than implement the interface.

## 1.18.0 — 2026-11-23

### Added

- **`readValues()`** reads a sequence of JSON documents in one file, one document at a time: pretty-printed one after another, one per line (JSON Lines, NDJSON), with nothing between them, or each after an RFC 7464 record separator — log files written by appending, `jq` output, exports glued together. Each document is checked as strictly as a whole file; only the sequence is what every other read refuses, as it should, so a stray `}` after an ordinary document is still caught. `limit`, `offset`, `onInvalidItem`, `maxElementBytes`, `BlockSize` and `bigIntAsString` apply, a document counting as an element. 200 000 documents: 207 ms one per line (a plain `fgets()` loop, which reads only that shape, takes 126 ms), 237 ms pretty-printed one after another.
  - Small documents are decoded a run at a time, every boundary between them made a comma — only where it stands outside a string, which is checked by counting the boundaries with the strings cut out and with them in, and only when the run decodes into as many documents as it had boundaries plus one. Either check alone catches a boundary inside a string; the test fails only with both removed.

### Fixed

- **A run that failed is not tried again over the same bytes.** Runs of elements, members or documents that could not be decoded at once used to be retried at every following entry whenever a single entry read fast in between had cleared the miss count — so a stretch where runs kept failing searched the same kilobytes again for every entry. Found while building `readValues()`, where such a stretch made the time quadratic; elsewhere it cost little, and a list built only to defeat runs now reads 15% slower, in exchange for never more than one look at any stretch.

## 1.17.0 — 2026-11-23

### Added

- **`bigIntAsString`**, a constructor option: an integer bigger than PHP's — a 20-digit id, an amount in the smallest unit — comes back as a string with every digit, instead of a float that has lost the last of them. It reaches every read: a list or an object, a run decoded at once, a wildcard, `**`, `readPaths()`, `getValue()`, temporary chunk files. Integers that fit stay integers.

### Tests

- `count()`, `getNth()`, `getLast()` and an offset are checked over long documents, where the elements they pass over go by in runs: the count, the keys, which element is the nth or the last, a repeated key at the end, and a broken element — refused at its own byte, or handed over and left out of the count.

### Not done, measured

- **Passing over elements without decoding them** — for `count()`, `getNth()`, `getLast()` and an offset — was built and dropped. The elements still have to be checked, so that a broken one is refused as before, and `json_validate()` checks 20 MB in 92 ms where `json_decode()` decodes them in 124 ms: nearly all of the time is the parsing both do. Built on `json_validate()`, `count()` took 149 ms against 142 ms decoding. Only not checking the passed-over elements at all would be faster — about 3× — and that would count a broken document instead of refusing it.

## 1.16.0 — 2026-11-23

### Faster

**`**` and `readPaths()` are two to three times faster.** A profile showed where their time went: each element was decoded on its own, checked on its own for a repeated name — a regex and a walk of every member — and walked with its cursors worked out afresh at every node, though under `**` every element of a list has the same cursors. Now:

- **They decode runs**, of a list's elements or an object's members, as the other reads have since 1.10.0 and 1.14.0.
- **The repeated-name check comes free under `**`.** The decoded walk passes every member anyway, so it counts the members named like a segment of the paths as it goes; one `substr_count()` per name on the run's bytes then shows whether `json_decode()` collapsed one. When the two differ — a repeated name, an escaped spelling, the name as a string value — the run is walked from its bytes the stream way, and what the decoded walk found is dropped unseen. Without `**`, a run gets the whole-run check once, not each element.
- **What a node's cursors do is worked out once per kind of node**, and remembered while the list is read: `**` expanded, the cursors that end there and those that go on, which child each name leads to, which paths end on which scalar. A list of a million alike elements is planned once. The memory for it is bounded.

| 200 000 records (20 MB) | 1.15.0 | now |
|---|---:|---:|
| `**.email` | 2.23 s | 0.86 s |
| `**.tags` | 3.00 s | 1.21 s |
| `**.zzz`, a name that is not there | 1.36 s | 0.79 s |
| `**.email` over an object keyed by id | 2.47 s | 1.04 s |
| `readPaths()`, two paths | 1.60 s | 0.86 s |

Peak memory under `**` is 0.22–0.33 MB.

## 1.15.0 — 2026-11-22

### Added

- **`JsonPointer::segments()`** reads an RFC 6901 JSON Pointer into a key path, so a path written for JsonMachine or any other tool that speaks it works unchanged: `keyPath: JsonPointer::segments('/orders/-/sku')`. `~1` is a slash and `~0` a tilde; `-` is every element of a list, as JsonMachine reads it; `''` is the whole document. A pointer is converted explicitly rather than recognised by its leading `/`, because a dotted path starting with `/` already means a member named that way.
- **`IterableSource`**, a source for bytes that arrive in pieces: anything that can be iterated for strings — a generator reading a socket or a decompressor, an HTTP client's stream of a response. The pieces are read as they come, never gathered, and can be any size.
- **Recipes for reading straight from an HTTP response**, with Guzzle and with Symfony HttpClient. The test suite checks them as PHP; running them needs those libraries and a network.

## 1.14.0 — 2026-11-22

### Faster

Runs — a few kilobytes of whole elements decoded by one `json_decode()`, which made reading a list four times faster in 1.10.0 — now serve three more reads:

| 200 000 records (20 MB) | 1.13.0 | now |
|---|---:|---:|
| an object keyed by id, read whole | 735 ms | 212 ms |
| `users.*.email` over a list | 740 ms | 310 ms |
| `users.*.email` over an object keyed by id | 870 ms | 385 ms |

- **An object's members** are decoded a run at a time — from a key to the last `},` within 4 KB, decoded in braces. `json_decode()` keeps one value for a repeated key where the stream hands over each, so the run's own members are also counted in its bytes — strings and nested containers cut out by PCRE, the commas left — and a run that lost one is read a member at a time.
- **A wildcard path** decodes a run of elements, or of members, and follows the rest of the path in each. A repeated name inside them is caught for the run as a whole, and the run's bytes are then walked the stream way. For the commonest path, `*.field`, the check is a count — the field's key in the run's bytes against the elements that came back with it — and only a run where the two differ gets the full one.

## 1.13.0 — 2026-11-22

### Added

- **`maxElementBytes`**, a constructor option for documents from someone else. Memory follows the biggest single element, and one 500 MB string is one element: read whole, it ends PHP with a fatal error nothing can catch. With a limit, an element bigger than it — a value handed over, a key on the way to it — is refused with `ElementTooLargeException` while it is being read: a 60 MB string under a 1 MB limit is given up at 1.1 MB, at a 1.7 MB peak, instead of the 115 MB it takes whole. A branch the path only skips is never held and is not limited. No limit by default.

### Security

- **`tempChunkDir` refuses a stream wrapper**, as the document path already did: `phar://`, `php://`, `data:` and the rest, in any case. It is the caller's setting, not the document's, but the directory is made, written to and read back, and through a wrapper — `phar://` above all — that was a filesystem operation nobody meant to hand to one.
- Audited again for 1.12.0: nothing in a document can run code — values go through `json_decode()` and `json_validate()` only, and there is no `eval`, `unserialize`, `include` or call by name anywhere in the library. Every hostile input tried — two million levels of nesting, under `**` too; a 100 000-segment path; 300 000 elements built to trip the fast paths; repeated keys and escapes against the repeated-name check — ends in an exception or a linear read, never a crash.

## 1.12.0 — 2026-11-22

### Changed

**The default block size is `BlockSize::Auto`.** It starts at 64 KB — what 1.11.0 used — and each time an element is read the slow way, because it did not fit or straddled two blocks, grows the block to hold eight of it, up to 1 MB. What decides the right block is the size of one element, and only reading finds that out: a file cannot be weighed in advance when it is a stream, and its length says nothing about its elements.

| default block | small records | 10 KB elements | 186 KB elements |
|---|---:|---:|---:|
| 1.11.0, 64 KB | 540 ms · 0.18 MB | 711 ms · 0.69 MB | 2.02 s · 7.9 MB |
| 1.12.0, `Auto` | 501 ms · 0.18 MB | 545 ms · 0.75 MB | 0.71 s · 12.8 MB |

Small records never move it. Large elements now cost up to 1 MB more — on a document whose elements already take megabytes once decoded. To keep the old bound, pass `blockSize: BlockSize::Kb64`.

Growing to hold two elements was tried first: 186 KB elements grew the block to 512 KB and read in 1.10 s; to hold eight, 0.71 s.

### Documentation

- The README's comparison and BENCHMARKS.md list every block size beside the other readers, and the README says which block to pick and why.
- `bin/benchmark.php` takes `--block-sizes`, to measure every `BlockSize`, and `--parsers=`, to measure only some readers.

## 1.11.0 — 2026-11-22

### Added

- **`BlockSize`**, a constructor option: the block the source is read in, from `Kb8` to `Mb1`; `Kb64` stays the default. The block is most of what the reader holds, and an element is read the fast way only when it ends within the block in hand — so a smaller block holds less, and a larger one lets large elements be read fast. Elements of 186 KB: 1.95 s with the default block, 0.67 s with `Mb1`. A million small records: 0.17 MB with the default, 0.12 MB with `Kb16`, a few percent slower. The README has the full table.

### Faster

- **An element full of nested objects is read the fast way.** It has a `},` at every object inside it, and the fast read looked at the first four and gave up, so every such element went to the PHP scanner. It now counts the brackets up to each `},` it passes and stops where they balance. Elements of 10 KB, 400 nested objects each: 1.98 s → 0.68 s for 20 MB.
- **Brackets inside strings no longer make a list slow.** Runs of elements could not balance across such strings, and each element retried its run eight times over 4 KB. A run of misses now stops the trying until the next block, as it already did for skipping. 100 000 records with `}` and `{{` in their strings: 4.6 s → 0.63 s.

## 1.10.0 — 2026-11-22

### Faster

**Reading a list is about four times faster, at a few hundredths of a megabyte more.** Per element, the PHP around `json_decode()` — finding the element, its delimiter, the calls between — cost four times the decode itself. Now a list's elements are decoded a run at a time: from one element to the last `},` within 4 KB, by one `json_decode()` of the run in brackets, and the PHP work is paid once per run.

A run that decodes is the document's own elements: a cut inside a string leaves an odd number of quotes and never decodes, so a run that does ends between elements. A cut inside an element leaves its brackets unbalanced, which is checked first and moves the cut back. A balanced run that still does not decode holds a broken element, and is read one element at a time — so the element is refused at its own byte, or handed to `onInvalidItem` with its own index, exactly as before.

| records | 1.9.0 | 1.10.0 | peak memory |
|---|---:|---:|---:|
| 100 000 | 197 ms | 52 ms | 0.15 → 0.16 MB |
| 1 000 000 | 2.00 s | 0.51 s | 0.15 → 0.16 MB |
| 200 000 larger records (20 MB) | 431 ms | 145 ms | 0.17 → 0.20 MB |

The run is 4 KB because that is where the speed stops coming: 16 KB and 64 KB were 2% to 6% faster still, at 0.40 MB and 1.20 MB.

### Documentation

- **BENCHMARKS.md and the README are measured again**, all six readers from ten thousand records to five million. This library is now 15 to 21 times faster than JsonMachine at every size, 1.2× to 1.8× behind `json_decode()` up to a million records, and ahead of it at five million — 2.7 s against 3.0 s, at 0.16 MB against 3.5 GB. Two figures in BENCHMARKS.md were also wrong for the dataset the script now writes: a record has four fields, not five, and `json_decode()` needs about 9× the file, not 7×.

## 1.9.0 — 2026-11-22

### Fixed

- **`*.*` over objects with a repeated name gave a different answer depending on where the element sat.** The decoded walk added in 1.8.0 guarded against a repeated name only through the names in the path, and `*.*` has none after the wildcard: `[{"a":1,"a":2},{}]` gave `[2]`, while the same element last in its list gave `[1, 2]`, which is what a wildcard over an object is documented to give. An element is now decoded and walked only when json_decode() kept every member: the members in its bytes — the colons left once the strings are cut out, by one PCRE pass — are counted against the members decoded.

### Faster

- **`**` and `readPaths()` follow small elements decoded**, as 1.8.0 began doing for one wildcard path: an element that ends within the block in hand is decoded in C and the cursors walk the decoded value, with the same guards — a repeated name, an escape, the depth `**` walks to — sending an element back to the stream walk where the two could differ.
- **Skipping a branch on the way to the path is done in C on PHP 8.3 and later.** The same guess at where a container ends is settled by `json_validate()`, which checks it without building a value; where it does not validate — or on PHP 8.1 and 8.2, which have no `json_validate()` — the skip walks it as before, and refuses a broken one with the same message. Each element of a long skipped list gets the chance, so a list too big for one block is skipped at the speed of its elements; a run of misses — a deep nest, say — turns the attempt off until the next block, so it never costs more than it saves.

| 200 000 records (20 MB) | 1.8.0 | now |
|---|---:|---:|
| `getValue()` of a value after a 20 MB list | 1.80 s | 0.38 s |
| `**.email` | 4.3 s | 2.2 s |
| `**.zzz`, a name that is not there | 2.7 s | 1.3 s |
| `readPaths()`, `users.*.email` and `users.*.id` | 3.8 s | 1.6 s |

Memory stays at 0.14–0.19 MB peak on each of these.

### Not done, measured

- **Handing elements over as raw JSON** would save almost nothing: `json_validate()` takes 95 ms where `json_decode()` takes 108 ms over the same 200 000 elements, so a raw read that still checks its elements is 3% faster, and one that does not would let a broken element through.

## 1.8.0 — 2026-11-22

### Faster

**A path that picks a field out of every element — `users.*.email` — is about four times faster.** It walked each element key by key in PHP, so taking one field cost five times as much as decoding the whole element. An element that ends within the block in hand is now decoded whole in C, and the rest of the path followed in the decoded value — decoded as objects, so a list and an object stay told apart as they are in the stream. Memory stays bounded: only an element that fits in the block is decoded.

The two walks must answer alike, and differ in one place: a repeated name, where the stream takes the first value and `json_decode()` keeps the last. An element that holds a name the path goes through more than once, or a `\u` escape that could spell one, is walked the stream way.

| 200 000 records (20 MB) | 1.7.0 | now | JsonMachine |
|---|---:|---:|---:|
| `users.*.email`, a list | 2.5 s | 0.64 s | 4.7 s |
| `users.*.email`, a map keyed by id | 2.6 s | 0.84 s | 3.4 s¹ |

<sub>¹ JsonMachine's `-` matches list indices only, so it reads every member whole and takes the field from it.</sub>

A pretty-printed document was checked too and needs nothing: PHP's own `JSON_PRETTY_PRINT` writes `},` together and breaks the line after the comma, so the 1.7.0 read is as fast on it — 200 ms against 192 ms for the same 100 000 records unformatted.

### Tests

- **JSONTestSuite is a test now.** Its 236 root-array documents are in `tests/fixtures/json-test-suite/`, with the suite's MIT licence, and `ConformanceTest` checks each one's verdict against `json_decode()`'s — and an accepted one's values — on every run. It was a script run by hand.

## 1.7.0 — 2026-11-22

### Faster

**Reading a list or an object of objects is three to five times faster.** An element used to be found by the PHP scanner — a stop at every quote and bracket — and then decoded by `json_decode()`, which read the same bytes again. Now `strpos()` finds where it probably ends, the next `},` or `],`; `substr_count()` rules out a place where the brackets do not balance; and `json_decode()` — needed anyway — settles it. An object or a list is complete at exactly one closing bracket, so a stretch from the opening one that decodes is the element, whatever its strings hold. Where it does not decode, or its end is not in the block in hand, the scanner reads the element as before — so a broken element is refused by the same code, with the same message.

| records | 1.6.0 | 1.7.0 | JsonMachine |
|---|---:|---:|---:|
| 100 000 | 600 ms | 206 ms | 1 026 ms |
| 1 000 000 | — | 1.93 s | 9.34 s |
| 5 000 000 (424 MB) | — | 9.6 s | 46.8 s |

Memory is unchanged at 0.15 MB. The README's figures are measured again; BENCHMARKS.md is not yet.

### Documentation

- NDJSON / JSON Lines: why this library does not read it — `fgets()` + `json_decode()` already streams it as fast as anything can, measured — and why a reader built for ONE document must not be given it: JsonMachine returned the first line's members and stopped, without an error.

## 1.6.0 — 2026-11-22

### Added

- **`**` in a key path: any number of levels, none included.** `**.email` is every member named `email` wherever it sits, through objects and lists; `users.**.email` includes `users.email`. Matches come in document order, an inner one right after the outer one it sits in. It works in `readGenerator()`, `read()`, `count()`, `readPaths()` and the rest; `getValue()` refuses it, as it does `*`.
  - A list it lands on is streamed element by element and each element searched before the next is read, so memory stays at one element — `**.users` over a 20 MB list peaks at 0.16 MB.
  - `**` must be followed by a name or an index: `a.**`, `**.*` and `**.**` are refused, since they would hand over every value in the document and every value inside each.
  - It is refused, not crashed on, past 512 levels of nesting — `json_decode()`'s own limit.
  - A branch whose raw bytes do not contain the name — and no backslash, which could spell it differently — is skipped whole instead of walked. On 200 000 records (20 MB): a name that is not there, 4.2 s → 2.7 s; `**.email`, 4.4 s → 4.2 s; a name in every record, 4.6 s → 4.9 s.

### Changed

- `readPaths()` with one path inside another's list — `a` and `a.0.b` — no longer holds the whole list: each element is handed over and then searched, in document order. The values are the same; where both paths match inside one element, the order follows the document instead of the paths.

## 1.5.0 — 2026-11-22

### Changed

**`*` walks the members of an object too.** It used to match the elements of a list only, and an object met by `*` gave nothing — so `users.*.email` over a map keyed by id, `{"users":{"u1":{...},"u2":{...}}}`, found nothing at all. JsonMachine's `-` cannot read that path either: it matches list indices only. It now yields every member's value, in document order; the names are skipped without being decoded. A path that used to cross an object with `*` and find nothing will now find its members.

### Added

- **`getValue($source, $keyPath)`** returns the one value a path names — a scalar as readily as a container — and stops reading there. `meta.total` at the head of a large export costs the bytes up to it. A path that landed on a scalar used to be refused by every read.
- **`onInvalidItem`**, a constructor handler: an element `json_decode()` refuses is handed over as an `InvalidItem` — its raw JSON, the reason, the byte it starts at, its index or name — and skipped, instead of ending the read. A broken structure still ends it.
- **`ProgressSource`** wraps any source and reports the bytes read after every 64 KB block, for a progress bar on a long read. It adds nothing to the parse.

### Faster

- A path through objects — `users.*.email` — skips the rest of each object in one pass once the member is found, instead of a key and three calls per remaining member: 2.9 s to 2.3–2.5 s on 200 000 records (20 MB), against JsonMachine's 4.8 s for the same path.
- A key without an escape is compared as its raw bytes, without `json_decode()`.

## 1.4.1 — 2026-11-22

### Fixed

- **A key path with thousands of segments no longer kills PHP.** Walking a path recursed once per segment, and about 7 000 segments ended the process with a segmentation fault — no exception to catch, at a length anyone who builds the path from a request could choose. A path now has at most 512 segments, `json_decode()`'s own depth; a longer one throws `InvalidArgumentException`.
- **`FILE://` and `FiLe://` are read like `file://`.** The stream-wrapper check matched other schemes in any case but this exception to it only in lower case, so an upper-case `file://` was refused as a wrapper.
- **`data:` without `//` is refused as a stream wrapper.** PHP opens `data:text/plain,...` as a data URI; it used to be turned away only because `is_file()` cannot stat one, and was reported as a missing file.

## 1.4.0 — 2026-11-16

Six bugs found by testing the reader against `json_decode()` on generated documents, and a skip that is twice as fast. **Read the first section before upgrading** — two of the fixes change what existing code gets back.

### Breaking

**A path ending in `*` hands each element over as it is.** `items.*` used to walk every element that happened to be a list: `[[1,2],{"x":1},[]]` came back as `1, 2, {"x":1}` — the inner lists flattened, the empty one gone, and `count()` with them. It now gives `[1,2]`, `{"x":1}`, `[]`, the same values as `items`. A path that ends in a named segment (`groups.*.items`) still streams the list it lands on.

**`read()` without `chunkSize` keeps an object's names.** `{"a":1,"b":2}` came back as `[[1, 2]]`; it is now `[['a' => 1, 'b' => 2]]`, as a chunked read and `readGenerator()` always gave.

### Fixed

- **A deeply nested branch on the way to the target no longer kills PHP.** Skipping it recursed once per level, and about 20 000 levels ended the process with a segmentation fault — no exception to catch, at a depth anyone controlling the document could choose. The skip is iterative now.
- **`tempChunkDir` reads objects.** Any object container failed with `Temporary chunk file ... must contain a JSON array list`.
- **`tempChunkDir` changes nothing about the values.** `3.0` and `1e3` came back as the ints `3` and `1000`; a name like `"10"` came back as the index `0`.
- **`readPaths()` with paths inside one another** — `a` and `a.0.b` — reported the inner one missing.
- **`readPaths()` no longer treats `*` as a member named `"*"`**, which `readGenerator()` never did.
- **The empty key is reachable** through the list form: `['', 'items']`. The dotted form still refuses `a..b`.
- Temporary chunk files are removed whatever stops the read, not only on the library's own exceptions.

### Faster

Skipping a branch on the way to the target scans strings and numbers a block at a time instead of a character at a time. On an 18 MB document whose target sits after everything else:

| | 1.3.0 | 1.4.0 | JsonMachine |
|---|---:|---:|---:|
| `keyPath` after the skipped data | 6160 ms | **2852 ms** | 4289 ms |
| wildcard `groups.*.items.*.id` | 5937 ms | **3460 ms** | 7134 ms |
| `readPaths()` | 5834 ms | **2809 ms** | — |

Reading a root array is unchanged: 100 000 records in about 615 ms.

### Tests

141 tests, line coverage 70% → 94%. A differential test reads 450 generated documents — nested three to six levels, paths three to five segments with wildcards, bytes handed over one to five at a time — and compares every API with `json_decode()`; each of the bugs above fails it on 1.3.0.

## 1.3.0 — 2026-09-06

Seven capabilities the comparable libraries had and this one did not. **Read the first section before upgrading** — three of these change what existing code does.

### Breaking

**A `forEach()` callback that returns `false` now stops the walk.** This is the sharp one: it is silent. A callback that happened to return `false` for its own reasons — the last line of a `foo() && bar()`, an early `return false` meaning "skipped this one" — used to be ignored and now ends the read. Only a strict `false` stops; `null`, `0` and `''` carry on as before.

**A root object is read instead of refused.** `{"u1": {...}, "u2": {...}}` used to raise `The JSON root value must be an array`; it now streams. Code that caught that exception to detect "this is not a list" will no longer see it. The same applies to a `keyPath` landing on an object. What is still refused is a root that is a single string, number, boolean or null.

**Error messages changed shape** — they now carry a byte offset. Anything matching on the exact text will need adjusting.

Implementations of `JsonChunkReaderInterface` other than the shipped one will need updating: the signatures widened and `readPaths()` was added.

### Complaints say where

```
Invalid JSON in file "data.json" at byte 30: Syntax error
```

The offset points at the START of the value that could not be read, not at wherever the scanner stopped — by the time `json_decode()` refuses an element, the stream has run past its end. Checked against JsonMachine's own positions on the same documents.

### Objects, not only arrays

A document keyed by id streams as `key => value`, at the root or through a `keyPath`. Chunked, the names stay with their values. This widened the JSONTestSuite audit from 236 in-scope files to 289 — with no verdict differing from `json_decode()`.

### Key paths as segments

`keyPath: ['a.b']` names one key that contains a dot — a domain, a version, `user.name`. The dotted string form could not reach it at all. `'*'` still means "every element of this list" in both forms.

### Several paths in one pass

```php
foreach ($reader->readPaths($file, ['users', 'logs']) as $path => $value) { ... }
```

Two paths used to mean two reads. Measured by counting the blocks the source is asked for: 44 for the two together, 66 for them separately.

### Arrays or objects

```php
new JsonChunkReader(associative: false);   // items come back as stdClass
```

The shape survives the detour through `tempChunkDir`. Object keys stay strings whatever this says.

### A string or an open stream

```php
$reader->read(new StringSource($payload, 'the upload'), keyPath: 'items');
$reader->read(new StreamSource(fopen('php://input', 'rb')), keyPath: 'items');
```

The reader never seeks, so a source that can be read once is enough. Earlier versions of the README said wrappers were refused because the reader "seeks and re-reads within the file" — it never did, and that sentence is gone.

### Unchanged

Reading well-formed files: JSONTestSuite still shows no verdict differing from `json_decode()` across 289 containers, and a full read of a 10 MB file takes ~479 ms at a 4 MB peak — slightly faster than 1.2.3.

122 tests, up from 103.

## 1.2.3 — 2026-09-06

- a stream wrapper is named as the cause
- the benchmark says what each parser is asked to do
- a stray function left the listener class

## 1.2.2 — 2026-09-06

- windowing lives in one place
- wildcard chunking is covered
- a numeric segment after a wildcard is covered

## 1.2.1 — 2026-09-06

- reader no longer shares its read buffer
- wildcard streams in a single pass
- a byte order mark is named as the cause
- failures raise without printing
- content after the root array is refused
- invalid arguments are refused at the call
- every README example is executed by a test
