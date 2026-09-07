NEON Specification and Conformance Corpus
=========================================

NEON is a human-readable data format for configuration, the format of every Nette
application since 2011. This repository holds its language-neutral specification and
the conformance corpus that every implementation runs in its tests, so that a document
reads the same in each of them, and is printed and edited the same.

- `spec/`: the grammar, the value model and how hosts map it, the error messages, the
  encoder, the lossless syntax tree and the editing operations, the `neon-lint` contract
- `corpus/`: the cases, in a format any language can read ([corpus/readme.md](corpus/readme.md))
- `tools/`: the oracle that derives the expectations from nette/neon 3.4

The corpus is public, and anyone writing their own implementation of NEON is welcome
to use it.


Running the corpus
------------------

An implementation checks this repository out at a pinned commit (`tests/neon-spec`) and
walks all its categories, one test per case. The runner is strict: an empty category,
an orphaned expectation or an unknown field fails instead of being skipped.

```
cd tools
composer install
php php-oracle.php check
```

checks that the expectations agree with the oracle.


Adding a case
-------------

1. Write the input into the category directory (`decode/`, `errors/`, `encode/` ...).
2. Run `php tools/php-oracle.php generate` to write its expectation.
3. If the specification deliberately differs from nette/neon 3.x, write the
   expectation by hand and add a sidecar `<name>.case.json` with the classification
   and the reason (see [corpus/readme.md](corpus/readme.md)).


License
-------

MIT
