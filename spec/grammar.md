# Grammar

This chapter defines which texts are NEON documents and the tree they produce. The
scanner is a state machine, not a regular expression: three of the five host
languages lack the possessive quantifiers and lookbehind the reference regex of
nette/neon 3.x uses. That regex is quoted in §1.7 as a reference; where the two
differ, the corpus decides.

Positions, line endings and errors are defined in terms of Unicode code points.


## 1. Lexical grammar

### 1.1 Input

The input is Unicode text. A host that receives bytes validates UTF-8 and fails with
`Invalid UTF-8 sequence.` at the position of the first invalid byte; a host with
UTF-16 strings fails the same way on a lone surrogate.

A byte order mark (U+FEFF) at the very start of the input is trivia of kind
`ByteOrderMark`. Anywhere else, U+FEFF is an ordinary character.

### 1.2 Tokens and trivia

The scanner turns the input into a stream of **tokens**, each carrying the **trivia**
around it. Printing all tokens with their trivia in order reproduces the input exactly.

Token kinds (`TokenKind`):

| kind | text |
|---|---|
| `String` | a quoted string including its delimiters (§1.4) |
| `Literal` | an unquoted scalar (§1.5) |
| `Colon` | `:` |
| `Equals` | `=` |
| `Comma` | `,` |
| `Dash` | `-` |
| `OpenBracket`, `CloseBracket` | `[`, `]` |
| `OpenBrace`, `CloseBrace` | `{`, `}` |
| `OpenParen`, `CloseParen` | `(`, `)` |
| `EndOfFile` | empty; always the last token |

Trivia kinds (`TriviaKind`):

| kind | text |
|---|---|
| `Whitespace` | a maximal run of spaces, tabs and lone CR characters |
| `LineEnding` | one `\n` or one `\r\n` |
| `Comment` | from `#` up to the line ending, excluding it; trailing spaces belong to the comment |
| `ByteOrderMark` | U+FEFF at the start of the input |

A lone CR (`\r` not followed by `\n`) behaves exactly like a space in every rule of the
scanner. Where this specification says "space", it means a space or a lone CR.

### 1.3 The scanner

At each position the scanner looks at the next characters and applies the first rule
that matches:

1. `'''` or `"""` followed by a line ending: a **multiline string** (§1.4.3).
2. `'`: a single-quoted string (§1.4.1).
3. `"`: a double-quoted string (§1.4.2).
4. `#`: a comment, trivia.
5. `\n` or `\r\n`: a line ending, trivia.
6. space or tab: whitespace, trivia; the run continues over spaces and tabs.
7. `,` `=` `[` `]` `{` `}` `(` `)`: the token of that kind.
8. `:` or `-`: a literal (§1.5) if the next character exists and is none of
   `" ' , = [ ] { } ( ) \n \t space`, and the previous character is neither `"` nor `'`;
   both characters then start the literal (`::` and `-:` are literals). Otherwise a `Colon`
   or `Dash` token.
9. any character other than `# " ' , : = [ ] { } ( ) \n \t space` and `` ` ``:
   a literal (§1.5).
10. anything else fails with `Unexpected '<text>'` at this position (§3).

When the input is exhausted, the scanner emits `EndOfFile`.

### 1.4 Strings

#### 1.4.1 Single-quoted

`'` opens the string. Then, repeatedly: two quotes `''` are an escaped quote and are
both consumed; any character other than `'` and a line ending is consumed. Then a `'`
must follow and closes the string. The consumption is greedy and never revisited, so
`'''abc'` is one string with the value `'abc`, and `'''abc` is unterminated.

#### 1.4.2 Double-quoted

`"` opens the string. Then, repeatedly: `\` followed by any character other than a
line ending consumes both; any character other than `"`, `\` and a line ending is
consumed. Then a `"` must follow and closes the string. Escape sequences are
validated later, when the value is evaluated (values.md §3).

An unterminated single-line string, or one interrupted by a line ending, fails with
`Unexpected '<text>'` at the position of its opening quote.

#### 1.4.3 Multiline

The opening triple `'''` or `"""` is followed by a line ending. The content runs up to
the first line **after the first line of the content** that starts with optional spaces
and tabs and then the same triple. That triple closes the token; whatever follows it on
the line is scanned normally (`'''` + `x` is a string followed by a literal).

The first line of the content is content even if it starts with the triple. Only when no
later line closes the string does such a first line close it, and the content is empty:
`'''`, line ending, `'''` is the empty string, but followed later by another line
starting with `'''`, the string runs up to that line. (This is how the possessive group
of the reference expression in §1.7 behaves.)

Inside the content, a line ending is any of `\n` and `\r\n`; a lone CR is content.

Without a closing line, or when the triple is not followed by a line ending, rule 1 does
not apply and the triple is read by rule 2 or 3 as single-line quotes: `'''abc'` is one
string with the value `'abc`, `"""` + line ending + `x` is the empty string `""` followed
by an unterminated `"`.

### 1.5 Literals

A literal starts as rules 8 and 9 of §1.3 say and continues as long as one of these
steps applies, trying them in order:

1. a run of characters other than `, : = ] } ) ( \n \t space`; so `[`, `{`, `#`, `"`
   and `'` may appear inside a literal (`the"string#literal`, `a[b`);
2. `:` followed by a character other than `\n \t space , ] } )`, and not at the end of
   the input (`a:b`, `::`);
3. a run of spaces and tabs followed by a character other than
   `# , : = ] } ) ( \n \t space` (`42 px`, `<literal> <literal>`).

Consequently a literal never ends with whitespace and never contains `,`, `]`, `}`,
`)`, `(`, `=` or a line ending. `a #b` is the literal `a` and a comment; `a :b` is the
literal `a` and the literal `:b`.

### 1.6 Attaching trivia

Trivia are attached to tokens by one rule, the same in every host:

- **Trailing trivia** of a token are the trivia that follow it up to and including the
  first line ending.
- **Leading trivia** of a token are the remaining trivia before it: blank lines,
  indentation, comment lines.
- The first token carries in its leading trivia everything from the start of the
  input, including the byte order mark. `EndOfFile` has only leading trivia.

So a line always ends in the trailing trivia of the last token standing on it, and
the leading trivia of a token contain a line ending only for the blank and comment
lines above it.

### 1.7 Reference: the regular expression of nette/neon 3.x

The lexer of 3.x matches, at each position, the first of these alternatives, joined
as `~(String)|(Literal)|...~Amixu` and applied by `preg_match_all` (anchored at each
match, case-insensitive, multiline, extended, UTF-8; spaces inside character classes
are significant). 3.x removes every `\r` before lexing, which the scanner above does
not. The regex explains the oracle; it is not a recipe for a port, the norm is the
scanner of §1.3 and the corpus.

```
String:     '''\n (?:(?: [^\n] | \n(?![\t ]*+''') )*+ \n)?[\t ]*+'''
          | """\n (?:(?: [^\n] | \n(?![\t ]*+""") )*+ \n)?[\t ]*+"""
          | ' (?: '' | [^'\n] )*+ '
          | " (?: \\. | [^"\\\n] )*+ "
Literal:    (?: [^#"',:=[\]{}()\n\t `-] | (?<!["']) [:-] [^"',=[\]{}()\n\t ] )
            (?: [^,:=\]})(\n\t ]++ | :(?! [\n\t ,\]})] | $ ) | [ \t]++ [^#,:=\]})(\n\t ] )*+
Char:       [,:=[\]{}()-]
Comment:    \#.*+
Newline:    \n++
Whitespace: [\t ]++
```


## 2. Syntactic grammar

The parser looks at tokens only. Trivia are visible to it through two questions:

- `startsLine(token)`: the token is the first token of the document, or the trailing
  trivia of the previous token end with a line ending;
- `indentationOf(token)`: for a token that starts a line, the text of the last trivia
  of its leading trivia if that is `Whitespace`, otherwise the empty string.

Indentations are compared as strings. Of two indentations, one must be a prefix of the
other, otherwise the input fails with `Invalid combination of tabs and spaces`.
"Longer" and "shorter" refer to the length of the string.

### 2.1 Document

```
document := value? EndOfFile
```

The value is a block array or a single value (a scalar, an inline array, an entity).
An input of trivia only has no value. Anything other than `EndOfFile` after the value
fails with `Unexpected '<text>'`.

The document starts as a block (§2.2) at the indentation of its first token.

### 2.2 Block array

A block at indentation `I` is a sequence of items, each on its own line indented
exactly by `I`. An item is either

```
Dash value?
key (Colon | Equals) value?
```

where `key` is a `String` or a `Literal` token; any other value followed by a colon
fails with `Unacceptable key`, and a key repeated within the block fails with
`Duplicated key '<key>'` (values.md §4 defines when two keys are equal). One block may
mix dashes and keys.

If the first line of a block is a value that is not followed by `Colon` or `Equals`,
and it is not a dash, the block is not an array: that value is the value of the
block, and it must be followed by a line ending or `EndOfFile`. Such a value in a later
line of the block fails with `Unexpected '<text>'` at the token that follows it, where
the colon was expected.

### 2.3 The value of a block item

What follows the `Dash` or `Colon` decides:

- **a line ending**: if the next token is `EndOfFile`, the value is missing. Otherwise,
  let `N` be the indentation of the next line that holds a token (blank and comment
  lines are skipped):
  - `N` and `I` are not prefixes of one another: `Invalid combination of tabs and spaces`;
  - `N` is longer than `I`: if that line starts an item (a `Dash`, or a key followed
    by `Colon` or `Equals`), the value is a nested block at indentation `N`; otherwise
    it is a **value on the next line** (`a:` + line ending + `  x` is `{a: x}`), which
    must be followed by a line ending or `EndOfFile`, and which ends the item;
  - `N` equals `I`, the item has a key and the next token is a `Dash`: a **dash
    subblock**, a nested block at indentation `I` that contains dashes only and ends
    at the first item with a key, or where the block ends;
  - otherwise the value is missing;
- **`EndOfFile`**: the value is missing;
- **another token**: an inline value (§2.5), which must be followed by a line ending
  or `EndOfFile`, otherwise `Unexpected '<text>'`; the exception for a dash is §2.4.

After an item, either the input ends, or the next line holding a token has an
indentation `N`: longer than `I` fails with `Bad indentation`; not comparable fails with
`Invalid combination of tabs and spaces`; shorter closes the block and its parent
decides; equal continues the block.

### 2.4 A block opened on the line of a dash

If the `Dash` is followed on the same line by another item (a key with a colon, or
another dash), a nested block opens whose first item stands on this line:
`- a: 1` + line ending + `  b: 2` is `[{a: 1, b: 2}]`, and `- - c` is `[[c]]`.

The indentation of that nested block is `I + "\t"` if the indentation of the next line
holding a token outside brackets starts with `I + "\t"`, and `I + "  "` (two spaces)
otherwise. If the block indented by a tab then fails, the error is `Invalid combination
of tabs and spaces` at the first token of that line, which is where 3.x fails, as it
tries the tab and then the two spaces. An implementation decides the indentation on
that line instead of trying both, which would cost time exponential in nested dashes.
So `- a: 1` + line ending + four spaces + `b: 2` is not `[{a: 1, b: 2}]` but fails with
`Bad indentation`: four spaces are neither a tab nor two spaces.

A value after the dash that is not an item (`- x`, `- [a]`) is simply the value of
the dash.

### 2.5 Inline values

```
value := (scalar | inline-array) entity-tail?
scalar := String | Literal
inline-array := open items close
```

`open` is `OpenBracket`, `OpenBrace` or `OpenParen`, `close` is its pair. Inside the
brackets, line endings and indentation carry no meaning. Items are separated by a
`Comma`, by a line ending, or by both; a trailing comma is allowed.

```
item := value | key (Colon | Equals) value?
```

The value after a key may be missing when the key is followed by a comma, a line
ending or the closing bracket (`{g:, h:}`). `{,}` and `{a, ,}` fail with
`Unexpected ','`. Keys are checked as in a block.

**Entity.** A value followed by `OpenParen` (whitespace in between is allowed:
`item (a, b)`) is an entity: the parenthesized inline array is its attributes. Then a
**chain** may follow: while the next token is a `Literal`, it is another link of the
chain, with attributes if an `OpenParen` follows it, and without them otherwise. A link
without attributes ends the chain (`first(a, b)second`, `1() 2()`, `[]()`). A single
entity is an `EntityNode`, two and more links an `EntityChainNode`.

### 2.6 Keys

A key is a `String` or a `Literal`. A literal key is evaluated with the flag "is a key"
(values.md §2): `true`, `null` and dates stay strings, numbers are evaluated (`42`,
`-1`, `0x10`, `5.3`). The key of a map and the check of duplicates use the canonical
key string (values.md §4).

### 2.7 Nesting

Arrays nest at most 500 levels deep: a block, an inline array and the attributes of an
entity each count as one level, any other value as none. The array at level 501 fails
with `Too deep nesting` at its first token. The limit is part of the format, so that
every implementation reads the same documents whatever the stack of its host and a
hostile input cannot exhaust it; nette/neon 3.x has no limit.


## 3. Errors

Every error of the scanner and the parser has a message and a position (errors.md).
The position is the start of the offending token, or the place where the scanner
failed. `Unexpected '<text>'` quotes the text of the offending token, or for the
scanner the rest of the input; the text is cut to 40 code points, a line ending
counting as one, and every line ending in it is shown as `<new line>`. `Unexpected end` is reported when a token is
required and the input has ended.

Line endings are trivia, but they are significant to the parser: where it requires
something on the current line and the line ends instead, the offending text is the line
ending. The error is then `Unexpected '<new line>'` at the position of the line ending,
with one `<new line>` for each line ending that follows it immediately, with nothing
between them, not even whitespace (`a: 1` + `\n` + `foo` + `\n\n` + `bar` fails with
`Unexpected '<new line><new line>'` at line 2, column 4). A comment before the line
ending is skipped as whitespace is.
