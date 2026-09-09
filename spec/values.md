# Values

This chapter defines what a document means. The meaning is given in a neutral model
(§1); each host maps the model to its own types (§8), and the table of deviations (§9)
says where that mapping is not one-to-one.

The parser evaluates every scalar while it builds the tree, so every error of this
chapter is an error of parsing: a document that parses always decodes, and a linter
that parses finds everything a decoder would.


## 1. The neutral model

A value is one of:

- **null**;
- **boolean**;
- **integer** of any size;
- **float**, an IEEE 754 double;
- **string**, a sequence of Unicode code points;
- **date** (§6);
- **entity**: a value and attributes (§7);
- **chain**: a sequence of entities (§7);
- **array**: an ordered sequence of entries, each with a key (§4, §5) and a value.

An array whose keys are exactly 0, 1, ..., n-1 in this order is a **list**; any other
array is a **map**. An empty array is a map when it was written with `{`, and a list
otherwise (`[]`, `()`). Every host keeps that distinction where its types allow it.

**Equality** of values, used by the corpus and by the editing operations
(mutations.md): values of different kinds are different, an integer is never equal to
a float (`1` is not `1.0`), the integer `-0` is `0`, the float `-0.0` is equal to
`0.0`, a NaN is equal to a NaN and an infinity to the infinity of the same sign, two
arrays are equal when they have equal entries in the same order and are both lists or
both maps, entities when their values and attributes are equal, chains when their links
are, and dates are equal component by component (§6), including the presence of an
offset and the written fraction. The equality does not depend on whether the value can
be encoded: writing the infinity of `1e999` back to its item changes nothing.


## 2. Literals

A literal that is not a key is evaluated by the first rule that matches its whole text:

1. `true`, `True`, `TRUE`, `yes`, `Yes`, `YES` are true; `false`, `False`, `FALSE`,
   `no`, `No`, `NO` are false; `null`, `Null`, `NULL` are null. Other spellings, and the
   words `on` and `off`, are strings.
2. A decimal number by the grammar
   ```
   [+-]? ( \d+ ( \. \d* )? | \. \d+ ) ( [eE] [+-]? \d+ )?
   ```
   Without `.` and without an exponent it is an integer (leading zeros are allowed:
   `0777` is 777, `-0` is 0, `+1` is 1); with either it is a float (`1.` is 1.0, `1e3` is
   1000.0). A float out of the range of a double is an infinity (`1e400`).
3. `0x[0-9a-fA-F]+`, `0o[0-7]+`, `0b[01]+`: an integer in that base, of any size. The
   prefix is lowercase and takes no sign: `0X10`, `-0x1` and `+0x1` are strings.
4. A date (§6), if its components make a valid one; otherwise rule 5.
5. Otherwise a string equal to the text.

Some consequences, all in the corpus: `1_000`, `1e+-1`, `1e`, `.`, `0x`, `0o8` are
strings; `01.5` is 1.5.

A literal that **is a key** skips rules 1 and 4: `true`, `null` and dates stay strings,
numbers are evaluated.


## 3. Strings

**Single-quoted** `'...'`: `''` is a quote; nothing else is interpreted.

**Double-quoted** `"..."`: these escape sequences are interpreted, everything else
between the quotes stands for itself:

| sequence | value |
|---|---|
| `\t` `\n` `\r` `\f` `\b` | tab, line feed, carriage return, form feed, backspace |
| `\"` `\\` `\/` | the character itself |
| `\_` | no-break space U+00A0 |
| `\uXXXX` | the code point; four hexadecimal digits of either case |
| `\uD8XX\uDCXX` | a surrogate pair, the supplementary code point |

Any other escape fails with `Invalid escaping sequence <seq>`, where `<seq>` is the
backslash and the following character, or, for `u` (or `U`) followed by four
hexadecimal digits, all six characters. A lone surrogate, or a pair in the wrong order,
fails with `Invalid UTF-8 sequence <seq>`, where `<seq>` is the six characters of the
first `\u` escape of it.

**Multiline** `'''` and `"""`: the value is the content between the line ending after
the opening triple and the line ending before the closing line. The **indentation** is
the leading run of spaces and tabs of the first line that is not empty; a line of
whitespace only is not empty and gives the indentation by its own content, while empty
lines at the start stay in the value as line endings. The indentation is removed from
the start of each line where it stands; a line that does not start with it is kept
whole. `"""` then interprets escape sequences as `"..."` does, `'''` does not; in
`"""`, a backslash at the end of a line stands for itself.

Errors of escape sequences are reported at the position of the string token.

In every string token, each raw CR of the source is removed from the value, whether it
belongs to a `\r\n` line ending or stands alone. A CR produced by the escape `\r` stays.


## 4. Keys

A key is a string or a literal evaluated as a key (§2). Its **canonical key** is a
string:

- a string key: the string itself;
- an integer: its decimal form, without sign for zero and without leading zeros
  (`0x10` is `16`, `-0` is `0`, `+1` is `1`);
- a float: its form with 14 significant digits, which is what PHP gives for a float
  converted to a string:
  - the value is rounded to 14 significant digits; let `e` be the decimal exponent of
    the result (`d.ddd × 10^e`);
  - if `-4 <= e < 14`, the positional form without trailing zeros and without a
    trailing point (`5.3`, `0.0001`, `1` for `1.0`, `1000` for `1e3`);
  - otherwise the mantissa without trailing zeros, `.0` if it has no fraction, then `E`,
    the sign of the exponent and its digits without padding (`1.0E+20`, `1.5E-9`,
    `1.2345678901235E+14` for `123456789012345.6`);
  - `-0.0` is `-0`, infinities are `INF` and `-INF`.

Two keys are equal when their canonical keys are equal (`{1.0: a, 1: b}` and
`{"1": a, 1: b}` are duplicates).

A key is an **integer key** when its canonical key is the decimal form of an integer
between -2^63 and 2^63-1: optional `-`, no leading zeros, not `-0`. This is what PHP
converts to an integer array key; `007`, `-0` and `99999999999999999999` as keys are
not integer keys.


## 5. Arrays

A block array and an inline array are an ordered sequence of items, each with a key or
without one. An item without a key gets an **implicit key**: one more than the greatest
integer key used so far in the same array, explicit or implicit, or 0 if there was none.
So `{5: a, b}` has the keys 5 and 6, `{-5: a, b}` the keys -5 and -4, and
`{a, 10: b, 5: c, d}` the keys 0, 10, 5 and 11. An implicit key beyond 2^63-1 fails
with `Implicit key overflow`.

An explicit key may **overwrite** an earlier item with an implicit key: `[a, 0: b]` has
two items in the tree and one entry in the value, `{0: b}`, at the position of the
first one. The check of duplicated keys covers explicit keys only.


## 6. Dates

A literal that is not a key is a date when its whole text matches

```
\d{4} - \d{1,2} - \d{1,2}
( ( [Tt] | spaces ) \d{1,2} : \d\d : \d\d ( \. \d* )? spaces? ( Z | [+-] \d{1,2} ( :? \d\d )? )? )?
```

(written here with spaces for readability; `spaces` is a run of spaces). A date is a
value made of its components as written: year, month, day, optionally hour, minute,
second, a fraction of a second kept as the string of its digits (`.5` and `.50` differ;
an empty fraction `19:00:00.` is no fraction), and an offset (`Z`, `+HH:MM`, `+HHMM`,
`+HH`, normalized to hours and minutes) or none.

The components must make a valid date and time: month 1 to 12, a day its month has in
that year (`2024-02-29` is a date, `2023-02-29` and `2024-04-31` are not), hour 0 to 23,
minute and second 0 to 59, an offset up to ±14:00 with minutes 0 to 59. A literal of the
shape of a date whose components do not is **no date but a string** (rule 5 of §2): an
invalid date neither fails nor turns silently into another one, as a calendar
normalization would (`2024-02-30` into `2024-03-01`).

A date without offset is **local**: the neutral model does not turn it into an
instant, and a conversion to an instant needs a time zone.


## 7. Entities and chains

An entity has a value (the value of its head) and attributes (an array by §5, the
items between its parentheses; empty parentheses give an empty list). A chain is a
sequence of entities; a link without parentheses has empty attributes.

For compatibility with PHP, the hosts represent a chain as an entity whose value is the
string `!!chain` and whose attributes are the list of the links; that string is the
constant `Neon.Chain` of every host. So that the input cannot forge a chain, an entity
whose head is the string `!!chain` fails with `Entity name '!!chain' is reserved`.


## 8. Host mapping

| model | PHP | JavaScript | Python | Go | Rust |
|---|---|---|---|---|---|
| null | `null` | `null` | `None` | `nil` | `Value::Null` |
| boolean | `bool` | `boolean` | `bool` | `bool` | `Value::Bool` |
| integer | `int`; beyond `PHP_INT_MAX` a decimal `string` | `number` up to 2^53-1, `bigint` beyond | `int` | `int64`; beyond it `*big.Int` | `i64`; beyond it decided with the port |
| float | `float` | `number` | `float` | `float64` | `f64` |
| string | `string` | `string` | `str` | `string` | `String` |
| date | `DateTimeImmutable` | `NeonDate` | `neon.Date` | `neon.Date` | `Date` |
| entity, chain | `Entity` | `Entity` | `Entity` | `*neon.Entity` | `Entity` |
| list | `array` | `Array` | `list` | `[]any` | `Vec<Value>` |
| map | `array` | plain object | `dict` | `*neon.Map` (ordered) | an ordered map of its own |

Map keys are canonical keys (§4); PHP converts integer keys to `int` as arrays do, the
other hosts keep strings.

A date is a value class of the library in every host but PHP, immutable and holding the
components as written, because the date types of the hosts cannot hold all dates of the
model (a day not in the month, a fraction of any length, a date without an offset). It
converts to the date type of the host explicitly (JavaScript `toDate(zone)`, Python
`to_datetime()`). The mapping of large integers in Rust is decided with its port.


## 9. Deviations of the hosts

The corpus marks each case limited to some hosts with `hosts` in its sidecar.

| deviation | hosts |
|---|---|
| A float with no fractional part cannot be told from an integer: the encoder writes a `number` that is an integer up to 2^53 as an integer, and a larger one as a float (larger integers are `bigint`). | JavaScript |
| The order of integer-like keys of a plain object is ascending, not the order of the document; the tree keeps the order. | JavaScript |
| Integers beyond `PHP_INT_MAX` are strings, and `encode()` writes them quoted. | PHP |
| An empty map is an empty `array`, so `decode()` cannot tell `{}` from `[]`. | PHP |
| `DateTimeImmutable` always has a time zone and a time: a local date gets the default zone of the server, also when it is written back, and a fraction is cut to microseconds. | PHP |

nette/neon 3.x is the historical baseline, not the norm. A case of the corpus whose
expectation differs from 3.x is classified in its sidecar as `fixed` (an accepted fix of
3.x) or `host` (a projection of the host); `open` (not decided) blocks a release.
