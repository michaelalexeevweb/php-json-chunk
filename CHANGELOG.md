# Changelog

Every release from 1.2.1 on. Earlier ones — 1.0.0 to 1.1.2 — are described only in the
[GitHub releases](https://github.com/michaelalexeevweb/php-json-chunk/releases).

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
