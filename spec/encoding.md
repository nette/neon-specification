# Encoding

`encode(value, blockMode = false, indentation = "\t")` writes a value of the neutral
model (values.md §1) as NEON. Equal values give the same text in every host; the
corpus `encode/` verifies both modes. The output is the output of nette/neon 3.x,
except for the fixes this chapter names, each of which makes `decode(encode(v))`
equal to `v` where 3.x does not.


## 1. Inline mode

| value | output |
|---|---|
| null, true, false | `null`, `true`, `false` |
| integer | decimal digits, `-` for a negative one |
| float | §4 |
| string | §3 |
| date | §5 |
| list | `[` items `]`, items as values separated by `, ` |
| map | `{` items `}`, items separated by `, ` |
| entity | the value, `(`, the attributes as items, `)` |
| chain | the links written one after another as entities |

An item of a map is `key: value`, where the key is written as a string by §3 if it is
not an integer key (values.md §4), and as the integer otherwise. Within one array, the
key is omitted where decoding would assign it anyway, by this rule: start with
`counter = 0` and `hiding = true`; for each entry with key `k`, omit the key if
`hiding` holds and `k` is the integer key `counter`; then, if `k` is an integer key and
`hiding` holds, set `hiding` to whether `k` equals `counter`, and `counter` to the
greater of `k + 1` and `counter`. So `{0: a, x: 1, 1: b}` is written `{a, x: 1, b}` and
`{5: a, 6: b}` keeps both keys.

Attributes of an entity follow the same rule, without brackets of their own: an entity
with no attributes is `value()`.


## 2. Block mode

A list or a map at the top level, or as a value of a block item, is written as a block:
one line per item, `- value` for an item whose key is omitted (§1) and `key: value`
otherwise, each line ending with `\n`. An item whose value is a non-empty list or map
is the prefix (`-` or `key:`), a line ending, and the block of the value with each
non-empty line indented by `indentation`; if that block does not already end with an
empty line, a line ending follows, so a nested block is always followed by one empty
line. Any other value is written inline after the prefix and a space.

An empty list is `[]` and an empty map `{}`, in both modes. A scalar, an entity or a
chain at the top level is written as in inline mode. Entities and their attributes are
always inline.

The indentation applies to every non-empty line of the nested text, including the
lines of a multiline string (§3.2).

*Fix of 3.x:* 3.x writes an empty map in block mode as `[]`.

### 2.1 How deep the blocks go

`blockMode` is `false` (inline mode), `true` (block mode at every level) or a number `n`: the
arrays at the first `n` levels are written as blocks, deeper ones inline. The top-level array is
level 1, so `1` writes one item per line with every value inline, and `0` is inline mode:

```
encode({a: {b: [1, 2]}, c: 3}, blockMode: 1)

a: {b: [1, 2]}
c: 3
```

A host may also take a callback `inline(value, path)` that is asked for every array that would
be written as a block, with the keys from the top-level value to it; when it returns true, that
array and everything in it is written inline. So a tool can keep short lists on one line:

```
encode(value, blockMode: true, inline: (v, path) => isList(v) && count(v) <= 3)

roles: [guest, member]
mail:
	host: smtp.example.com
```

The callback is part of the API of a host, not of the corpus; the corpus verifies the depth
(`blockMode` in the sidecar of a case, corpus/readme.md).

The number is a non-negative integer; `indentation` is a non-empty run of spaces and tabs.
Anything else is refused, because another indentation would change what the output means.


## 3. Strings

### 3.1 Single-line

A string is written **without quotes** if and only if all of these hold:

1. the scanner (grammar.md §1.3) reads it as one `Literal` token covering the whole text;
2. it contains no control character U+0000 to U+001F;
3. it does not start with a digit, or with `+`, `-` or `.` followed by a digit;
4. ignoring case, it is none of `true`, `false`, `yes`, `no`, `on`, `off`, `null`;
5. the literal evaluates to the same string (values.md §2).

Rule 1 alone excludes the empty string, leading and trailing whitespace and every
character the grammar reserves; rule 3 excludes most numbers and dates, and rule 5 the
rest (`-.5`). Otherwise:

- a string containing a control character other than tab is written as a JSON string:
  `"` ... `"`, with `\"`, `\\`, `\b`, `\f`, `\n`, `\r`, `\t`, other control characters as
  `\u00xx` in lowercase hexadecimal, U+2028 and U+2029 as `\u2028`, `\u2029`, and
  everything else, slashes and non-ASCII included, unescaped;
- any other string is written in single quotes, each `'` doubled; a tab stays literal.

*Fix of 3.x:* 3.x lacks rule 5 and writes `-.5` and `+.5` without quotes, which decode
as floats.

### 3.2 Multiline

A string containing `\n` is written as

```
'''
<indentation><line 1>
<indentation><line 2>
'''
```

each non-empty line of the value indented by `indentation`, empty lines left empty,
the closing triple at the start of its line. The form switches to `"""` if the value

- contains a control character other than tab and line feed (a CR among them), or
- has a line, the first one included, that starts with optional spaces and tabs
  followed by `'''`, which the decoder would read as the closing line, or
- starts its first non-empty line with a space or a tab, which the decoder would take
  for indentation (a line of whitespace only counts as non-empty).

In the `"""` form, control characters other than tab and line feed are escaped as in
§3.1, `\` is written `\\`, `"""` is written `""\"`, and the spaces and tabs at the start
of the first non-empty line are written as `\u0020` and `\t`. The value of two spaces, `a`, a line
feed and `b` is written, with the default indentation, as

```
"""
	\u0020\u0020a
	b
"""
```

*Fix of 3.x:* 3.x switches to `"""` for the second condition only when the triple is
preceded by whitespace, and not at all for the third, so its output of such values
does not decode back to them.


## 4. Floats

1. The digits are the shortest sequence that reads back as the same double (PHP with
   `serialize_precision = -1`, JavaScript `Number.prototype.toString`, Python `repr`,
   Go `strconv.FormatFloat(f, 'g', -1, 64)`, Rust `Display`). Let `e` be the decimal
   exponent (`d.ddd × 10^e`).
2. If `-4 <= e < 17`, the positional form: `0.0001`, `1000000000000000.0`,
   `0.30000000000000004`.
3. Otherwise the exponent form: the mantissa, `e`, the sign of the exponent and its
   digits without padding: `1.0e+17`, `1.5e-5`, `5.0e-324`.
4. A form without a fractional part gets `.0`, in the exponent form in the mantissa
   (`1.0`, `1.0e+25`, never `1e+25.0`).
5. `-0.0` is `-0.0`. An infinity or NaN fails with `INF and NAN cannot be encoded to NEON`.


## 5. Dates

A date is written as `YYYY-MM-DD`, then, if it has a time, a space and `HH:MM:SS`
with the fraction as written (`.5`), then, if it has an offset, a space and the offset
as `+HHMM` or `-HHMM`. All components are zero-padded to their width.

*Host:* a PHP `DateTimeImmutable` always has a time and a time zone, so PHP always
writes both (`2016-06-03 19:00:00 +0200`); the fraction is its microseconds without
trailing zeros, left out when they are zero.

*Fix of 3.x:* 3.x drops the fraction.
