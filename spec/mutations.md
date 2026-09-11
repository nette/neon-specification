# Editing

The tree is edited by methods of its nodes (model.md §4). This chapter defines what
they do to the text, so that an edit gives the same text in every host; the corpus
`mutations/` verifies it.

The guiding principle: **an edit changes only what it edits.** Comments, blank lines,
indentation, block or inline style, quoting and the spelling of numbers stay where the
edit does not reach.


## 1. Paths

The editing facade of `DocumentNode` addresses items by a **path**, a list of segments
from the root. A segment is a key: the same canonical key the entry has in the decoded
value (values.md §4 for explicit keys, §5 for implicit ones). So `['list', 2]` finds the
third item of a plain list as well as an item with the explicit key `2`; where an
explicit key overwrote an implicit one, the path finds the item that won in the value.
A path addresses what `decode()` addresses. What it cannot reach (the overwritten one of
two colliding items, an item by its order in a mixed array) a program reaches through
`items[n]`, and the corpus writes such a segment as `{"item": n}`.

An intermediate segment that does not exist is always an error, with the message
`No item '<segment>' in the path <path>` (segments joined by `.`). A segment may continue
into the attributes of an entity, as `decode()` gives them: `['services', 'mailer',
'timeout']` reaches `timeout: 30` in `mailer: Mailer(timeout: 30)`. A key whose value is
null is an existing parent: writing below it makes it an array (`extensions:` with
`['extensions', 'console']` becomes a block with one item).

Removing by a path removes the key from the value: the item found, and the items with the
same key whose value it overwrote, so that no overwritten value comes back.


## 2. Ownership of trivia

What belongs to an item is what goes with it when it is removed or moved:

- the comment on the line of the item (in the trailing trivia of the last token of its
  line; for `key: # c` in the trailing trivia of the colon);
- the comment lines **directly** above it: the contiguous block of comment lines in the
  leading trivia of its first token with no blank line between them and the item.

What lies above, separated from the item by a blank line (a file header, a license, a
section heading `# --- Database ---`), is a **preamble** and does not belong to the
item, and neither do the blank lines, which are separators.


## 3. Operations

### 3.1 `ArrayItemNode.remove(comments = Drop, mergeBlankLines = false)`

Removes the item with the comments it owns:

- `CommentPolicy.Drop` drops them: a comment above a key of a configuration describes
  that key, and above the next one it would lie;
- `CommentPolicy.MoveToNextToken` keeps them above the next item, or for the last item
  before the closing bracket or `EndOfFile`, always together with their line endings,
  so that they never comment out the following syntax. The comment on the line of the
  item becomes a comment line of its own, after the lines that stood above the item.

What follows the item, the comments above the next one included, stays as it was.

The preamble and the separators move to the next token whatever the policy. With
`mergeBlankLines`, the narrower of the two gaps around the item remains between its
neighbours.

**Removing the last item keeps the collection.** A block that loses its last item is
replaced in its slot by an empty inline array, `[]` if the items had dashes and `{}`
if they had keys, carrying the edge trivia of the block, so the value stays an empty
collection, not null. An inline array stays `[]`, `{}` or `()`. An item or a document
marked `nullable` gets `null` instead (`key:` without a value, or an empty document),
because for it empty and nothing mean the same. `nullable` is a property of
`ArrayItemNode` and `DocumentNode`, false by default; an editor sets it from the schema
of the option.

### 3.2 `ArrayItemNode.setValue(value | node)`

Replaces the value of the item.

**Writing an equal value is a no-op.** If the new value equals `getValue()` by the
equality of values.md §1, nothing changes: saving an unchanged form does not rewrite
`0x10` as `16`, `YES` as `true` or `1.00` as `1.0`.

**A changed value keeps the style where it can be derived:** an integer in the base of
the original token (`0x`, `0o`, `0b`), a boolean in the family and the case of the
original (`yes`/`no`, `TRUE`), a string in the quotes of the original if the value can
be written in them; otherwise the canonical form of the encoder (encoding.md). A float
is always written canonically.

The new node takes the edge trivia of the old one: the leading trivia of its first
token and the trailing trivia of its last. The transitions:

- scalar to scalar: only the token changes;
- null to scalar: a `Whitespace " "` is put after the colon, and the line ending moves
  from the colon to the last token of the value;
- scalar to null: the reverse;
- scalar to block: the line ending stays after the colon, and the items of the block
  get the indentation of the parent plus one unit (§4);
- block to scalar, and an inline array, which is replaced as a scalar;
- a **value on the next line** (grammar.md §2.3) stays on its line, and only its token
  changes.

A host array given to an item without a value becomes a block if the parent is a
block, and an inline `[]` or `{}` array otherwise.

The root is a place of its own: a block there has the indentation of the old root block,
or none. The first value of an empty document comes after what the document holds,
the byte order mark, the comments and the blank lines; `DocumentNode.setValue([], null)`
leaves them in the document, and the comments of the removed value too.

`setValue` verifies the shape **before** any change: a block in an inline item, a key
without a colon, a key colliding with a sibling, arrays nested deeper than grammar.md
§2.7 allows are refused, and a refused operation
leaves the text, the parents and the trivia unchanged. `replaceWith(node)` on any node
does the same from the side of the child.

### 3.3 `addItem(key?, value, position?)`

Inserts an item at the end, or at the position, of a block or an inline array.

- In a block, the item gets the indentation of its siblings (that of the first token
  of the last sibling) and a line ending in the style of the document.
- In an inline array, the `separator` of an item is the comma after it. Appending gives
  a comma and a `Whitespace " "` to the former last item, and the new one has no
  separator; if the former last item had a trailing comma, the new one takes it over.
  Inserting elsewhere gives the new item a comma and a space after it.
- In an inline array spread over several lines, the style of the neighbours is copied:
  the new item goes on a line of its own with their indentation.
- An item inserted before another one takes over only the preamble above it, in a block
  and in an inline array alike; the comment lines the other one owns (§2) stay above it.

`DocumentNode.setValue(path, value)` on a path whose last segment does not exist adds
the item at the end of its existing parent; with `{create: false}` it fails, so that a
typo in a migration does not create a new key.

### 3.4 `insert(position, item)` and `detach()`

`detach()` takes a node out with its edge trivia; the source tree stays canonical, and
the emptied slot gets null only where the slot allows it, otherwise `detach()` is
refused and `replaceWith` is the way. The slots that allow it are the value of an item
with a key or a dash, and the root, which leaves its preamble and the byte order mark
in the document.

`replaceWith(node)` is decided by the slot the node stands in, not by the class of its
parent, and the new node takes the edge trivia of the old one:

| slot | what it does | what it takes |
|---|---|---|
| value of an item | `setValue(node)` | a node allowed there (§3.2) |
| key of an item | `setKey()` with the node | a `StringNode` or `LiteralNode` on one line |
| root | `DocumentNode.setValue([], node)` | any value node |
| head of an entity | the token or array changes | a `StringNode`, `LiteralNode` or `InlineArrayNode` |
| link of a chain | the link changes | an `EntityNode`, with attributes where the old link had them |
| attributes of an entity | the array changes | an `InlineArrayNode` in parentheses |

The public slot setters (`item.value = node`, `document.value = node`) are the same
operations, not a raw write. `insert` puts a finished item into an array, also
one detached from elsewhere: it is **reindented** relative to its new place, which
shifts the common prefix of the indentation of all its lines; the lines inside the token
of a multiline string do not change, as they are part of its lexeme and its value.

The item keeps the comments it owns (§2). Into a block they move with it. Into an inline
array they become comment lines above the item, the comments between its key and a value
on a later line and the one on its line after the lines that stood above it, and the item
starts a line of its own.
An inline array on one line cannot hold a comment, so an item owning one is refused
there with `An item with comments cannot stand in an inline array on one line.`, before
anything changes.

### 3.5 `setKey(key)`

Replaces the key of an item, in the quotes of the original key if the new key can be
written in them; a key colliding with a sibling is refused.


## 4. Layout

Where an edit has to create text, it derives the layout locally, near the place of the
edit:

- the **indentation unit** is the difference between the indentation of the nearest
  nested block and that of its parent, looked for among the siblings, the nearest first,
  then the ancestors, then the whole document; otherwise `"\t"`. A block opened on the
  line of a dash (grammar.md §2.4) does not count, its indentation is special; a replaced
  block keeps the indentation it had;
- the **line ending** is the `LineEnding` of the nearest preceding token, otherwise the
  first one of the document, otherwise `"\n"`;
- a **missing final line ending** is a property of the format and is kept: an item
  appended after a last line without a line ending gets the line ending before itself
  and has none of its own.

Inserting into an empty array, at the start of an array and after its last item are
three cases, each in the corpus. In an inline array with the line ending before the
comma (`[a` + line ending + `, b]`), a new item follows the style of its neighbours.

New values are written as `encode()` writes them (encoding.md), indented for their place.


## 4a. Keys and traps of the grammar

An edit must not change what the rest of the document means. Four rules follow from the
grammar:

- **The keys of the other items do not change.** A key written in the file is never
  rewritten. An implicit key follows the rule of values.md §5, so an edit can shift it:
  in a list (a value that decodes to a list), an item added or removed without a key
  renumbers the following items, which is what a list means; anywhere else, an item
  whose implicit key would change gets its old key written out (`{5: a, b}` without
  `5: a` is `{6: b}`). An item with a block opened on the line of its dash gets the key
  on its own line, the block below it.
- **A key without a value followed by a dash** at the same indentation would read as a
  dash subblock (grammar.md §2.3), so such a key gets an explicit `null`; and a dash that
  comes right after a dash subblock gets its key written out, which ends the subblock.
- **An empty `'''` string closed on the line right after its opening** is closed by any
  later line that starts with `'''` (grammar.md §1.4.3). In a document that holds one,
  new multiline strings are written with `"""`.
- **Spaces on the last line of the input** would be read as the indentation of a missing
  value; when the last item loses its value, they are removed.


## 5. Guarantees

Each of these is verified over the whole corpus `mutations/`, after every operation of
a sequence, not only at its end:

1. **lossless:** `print(parse(s)) === s` for the accepted input;
2. **value:** `decode(print(tree))` equals the value the case writes down independently,
   not the one the implementation would produce;
3. **valid tree:** `parse(print(tree))` has the same dump, parents agree with a walk of
   the tree, trivia are canonical, and no token stands in the tree twice;
4. **locality:** for `set` of a scalar, the text before and after the original lexeme is
   unchanged byte for byte; for `add` and `remove` only the lines of the item, one
   adjacent separator or line ending, and the trivia §2 grants the item may change.
   The repairs of §4a are the one exception, because the meaning of the rest wins over
   locality: a key written out or a `null` added elsewhere, each written down in the
   exact expected text of its case, never more than the rule asks for.

A refused operation leaves the text, the parents and the trivia unchanged.


## 6. Operations of the corpus

`mutations/<name>.ops.json` is a list of operations applied in order to
`<name>.neon`; `<name>.expected.neon` is the text after all of them, and an optional
`<name>.expected.<n>.neon` the text after the n-th one. The value after the operations is
what they do to the decoded value of the input; where the rules of §4a make it differ
from a plain edit of that value, the case writes it down in `<name>.expected.json`, and
with more operations the value after each earlier one in `<name>.expected.<n>.json`. A
runner checks the value, the guarantees of §5 and the locality after every operation,
and that a refused one (`"expectError": "<message>"`) leaves everything as it was.

```json
[
	{"op": "set", "path": ["a", "b"], "value": <typed value>},
	{"op": "set", "path": ["a"], "value": <typed value>, "create": false},
	{"op": "add", "path": ["list"], "key": "x", "value": <typed value>, "position": 0},
	{"op": "remove", "path": ["a"], "comments": "drop", "nullable": false, "mergeBlankLines": false},
	{"op": "setKey", "path": ["a"], "key": "b"},
	{"op": "set", "path": ["a"], "value": <typed value>, "expectError": "Duplicated key 'x'"}
]
```

A value is in the typed form of the corpus (corpus/readme.md); `comments` is `drop` or
`move`; a segment of a path is a key or `{"item": n}`. A case with `expectError`
verifies that the operation is refused with that message and changes nothing.
