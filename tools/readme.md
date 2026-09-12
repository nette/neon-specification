# Tools of the Corpus

`php-oracle.php` generates the expectations of `decode/`, `errors/` and `encode/` from
nette/neon 3.4, the historical baseline of the format, checks them, and checks that 3.4
accepts every input of `roundtrip/` and `cst/`:

```
composer install
php php-oracle.php generate      # writes the expectations of every case
php php-oracle.php check         # verifies them; exit code 1 on any difference
php php-oracle.php selfcheck     # verifies that check fails on each kind of broken corpus
```

`check` fails closed: a missing or empty category, a file without its input, an unknown
field of a sidecar, a missing `spec/VERSION` or `oracle.json` are failures, and then no
case is checked at all, so a broken corpus never looks green.


For a differential run of an implementation over any set of real-world files,

```
php php-oracle.php outcomes <dir>... > outcomes.jsonl
```

writes one JSON line per `.neon` file: its typed value, or its error with the position,
as 3.x sees them in the terms of the specification.


## The environment is pinned

An oracle running in a different environment lies, so the script requires it and sets
what it can by itself:

- nette/neon exactly the version locked in `composer.lock`;
- 64-bit PHP 8.4 or newer with `bcmath`;
- `serialize_precision = -1` and `precision = 14` (with `serialize_precision = 17`,
  `Neon::encode(0.1)` gives `0.10000000000000001`);
- `date.timezone = UTC`.

The environment of the last generation is written to `corpus/oracle.json`, and `check`
fails when the current one differs.


## What the oracle normalizes

The oracle reports the behaviour of 3.x in the terms of the specification, so that
the generated expectations need no hand edits:

- columns are counted in code points (3.x counts bytes);
- the text quoted by `Unexpected '...'` shows every line ending as `<new line>`;
- `Invalid UTF-8 sequence.` gets the position of the first invalid byte (3.x reports none);
- a byte order mark at the start of the input is removed before decoding, as
  `Neon::decodeFile()` does;
- the typed value of a document is built from the syntax tree of 3.x, so that an empty
  `{}` is a map and a date keeps the text of its literal, and then checked against the
  value of `Neon::decode()`.

An input with a lone CR, or a date without offset in `encode/`, cannot be expressed by
3.x and needs a sidecar with a hand-written expectation.
