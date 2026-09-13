# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Project overview

The language-neutral specification of NEON and the conformance corpus that every
implementation runs (`nette/neon` for PHP, `@nette/neon` for JavaScript and the others).
It ships no code of a library; the only code is the oracle in `tools/`.

- `spec/`: the specification, the single source of truth of the format, the value
  model, the syntax tree, the editing operations and the `neon-lint` contract.
  `spec/model.md` is the one home of the vocabulary (token and trivia kinds, nodes,
  slots, corpus operations); implementations refer to it and do not copy it.
- `corpus/`: the cases; their formats are described in `corpus/readme.md`.
- `tools/php-oracle.php`: generates and checks the expectations from nette/neon 3.4.

## Essential commands

```bash
cd tools && composer install
php php-oracle.php check       # the corpus agrees with the oracle (exit code decides)
php php-oracle.php generate    # (re)writes the generated expectations
```

## Working in this repo

- **Every expectation comes from the oracle**, never from memory or from an
  implementation. A case that deviates from 3.x gets a sidecar `<name>.case.json` with
  the classification `fixed` or `host` and a reason; the oracle checks that 3.x still
  disagrees with the hand-written expectation.
- **Inputs are bytes.** Never let an editor or git touch their line endings, trailing
  whitespace or the final newline; `corpus/**` is `-text`.
- **The corpus is versioned with the specification.** `spec/VERSION` is the version an
  implementation declares; a change that alters an expectation of an existing case
  raises it.
- A change of the corpus and the change of an implementation that it requires are two
  commits in two repositories.
