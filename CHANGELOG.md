# Changelog

Every release from 1.2.1 on. Earlier ones — 1.0.0 to 1.1.2 — are described only in the
[GitHub releases](https://github.com/michaelalexeevweb/php-json-chunk/releases).

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
