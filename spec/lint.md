# `neon-lint`

Every implementation ships a command line linter with this contract, so that an editor,
a CI job or an AI agent can call any of them the same way.

```
neon-lint [options] <path>...
```

A path is a file, a directory (searched recursively for `*.neon`) or `-` for the
standard input.


## Output

One line per finding on the standard output:

```
<path>:<line>:<column>: <message>
```

with the message and the position of errors.md. Nothing is printed when everything is
valid; `--verbose` prints `OK <n> files` instead. No colors when the output is not a
terminal, no banner: the output is read by machines.


## Exit codes

| code | meaning |
|---|---|
| 0 | every file is valid |
| 1 | at least one finding, or no file found |
| 2 | a wrong invocation, or a failed internal invariant of the tree (with a request to report it) |


## Options

| option | effect |
|---|---|
| `--check` | also verifies the round trip: parse and print, a difference is a finding |
| `--json` | the findings as a JSON array of `{path, line, column, message}` |
| `--quiet` | no output, only the exit code |
| `--verbose` | `OK <n> files` when everything is valid |


## Operational details

- Files are processed in a deterministic order, sorted by path.
- The recursion skips `vendor`, `node_modules`, `.git` and every directory whose name
  starts with a dot; symbolic links are not followed. A path given is followed.
- A file given twice, under the same or another path, is linted once.
- A path given that does not exist is a finding `<path>: No file found`, also when other
  paths are valid; when the paths give no file at all, that is the finding too.
- A directory that cannot be read is a finding with the message of the host.
- An unreadable file is a finding with the message of the host and no position
  (`<path>: <message>`).
- Invalid UTF-8 is a finding at the position of the first invalid byte.
