# Errors

## 1. The error object

Every error of the scanner, the parser and the evaluation of values is one class of
the host: `NeonError` in JavaScript and Python, `Nette\Neon\Exception` in PHP,
`*neon.Error` in Go, `neon::Error` in Rust. It carries:

- `message`: the text of the catalog (§2), without position;
- `position`: `{line, column, offset}`, 1-based line and column and 0-based offset, all
  in Unicode code points; null only where the catalog says so.

The textual form joins both: `<message> on line <line> at column <column>`. In PHP,
`getMessage()` returns that joined form for compatibility, and the bare message is
`getDescription()`. The corpus verifies the bare message and the position, never the
joined string.


## 2. Catalog

The text of a message is part of the contract.

| message | when | position |
|---|---|---|
| `Invalid UTF-8 sequence.` | the input is not valid UTF-8, or holds a lone surrogate | the first invalid byte or code unit |
| `Unexpected '<text>'` | a token or a character that the grammar does not allow here | the token, or where the scanner failed |
| `Unexpected end` | the input ended where a token is required | the end of the input |
| `Invalid combination of tabs and spaces` | two indentations neither of which is a prefix of the other | the first token of the line |
| `Bad indentation` | a line indented deeper than its block allows | the first token of the line |
| `Unacceptable key` | a key that is not a string or a literal | the key |
| `Duplicated key '<key>'` | an explicit key repeated in one array; `<key>` is the canonical key | the key |
| `Implicit key overflow` | an implicit key beyond 2^63-1 | the value of the item |
| `Invalid escaping sequence <seq>` | an unknown escape in a double-quoted string | the string |
| `Invalid UTF-8 sequence <seq>` | a lone or misordered surrogate escape | the string |
| `Entity name '!!chain' is reserved` | an entity whose head is the string `!!chain` | the head |
| `Too deep nesting` | an array at level 501 (grammar.md §2.7) | the first token of the array |

`Unexpected '<text>'` quotes the text of the offending token, or for an error of the
scanner the rest of the input from the failing position. The text is cut to 40 code
points, a line ending counting as one, and every line ending in it is written as
`<new line>`.


## 3. Errors of the encoder

| message | when |
|---|---|
| `INF and NAN cannot be encoded to NEON` | a float that is infinite or not a number |

A value that the host cannot encode (in JavaScript `undefined`, a function, a symbol,
an instance of a class, a cycle) fails with an error of the host that names the path
to the value; it is never encoded silently as `null`. The same holds for a value whose
text would decode as another value: a chain with fewer than two links or a link that is
not an entity, an entity whose value is an entity or a chain, a date whose components make
no valid date (values.md §6) or whose year is not four digits, two keys of one array
with the same canonical key (in JavaScript `1` and `'1'` in a `Map`), arrays nested
deeper than grammar.md §2.7 allows, and a cycle through an entity as well as through an
array.
