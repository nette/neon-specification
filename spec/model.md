# The Syntax Tree

Every implementation parses NEON into the same lossless concrete syntax tree. Every
token of the source is in the tree, and every character of the source belongs to a
token or to its trivia, so printing the tree is a concatenation and
`print(parse(s)) === s` holds byte for byte for every input the parser accepts.

This document is the one home of the vocabulary: token and trivia kinds, node classes,
slot names, the names of queries and mutations. Implementations refer to it and do not
copy it; their tests check their exports against it.


## 1. Tokens and trivia

### 1.1 Token

| member | access | note |
|---|---|---|
| `kind: TokenKind` | read-only | a token changes its kind only by being replaced |
| `text: string` | read; `setText(text)` | the setter scans the text and rejects anything that is not one token of the same kind |
| `leadingTrivia`, `trailingTrivia: Trivia[]` | read; `setLeadingTrivia()`, `setTrailingTrivia()` | the setters verify the canonical form (§1.3) |
| `parent: Node \| null` | read; written by the tree | |
| `origin: Position \| null` | read-only | where the token stood in the source it was read from; null for a token made otherwise |
| `getPosition(): Position \| null` | method | the current position, computed by a walk from the root; null in a detached subtree |
| `Token.fromText(text)` | static factory | the public way to make a token; the scanner decides its kind |
| `is(kind \| text)` | query | |

The token kinds are those of grammar.md §1.2. A kind is a name, not a character; the
text of the token carries the character.

### 1.2 Trivia

`Trivia { kind: TriviaKind, text }` is an immutable value. `Trivia.fromText(text)`
verifies that the text is whitespace, one line ending, one comment or a byte order
mark; `withText()` returns a copy; `getCommentText()` returns the text of a comment
without `#`, trimmed. Trivia carry no position. One instance may sit on several tokens,
so trivia are replaced by identity (`token.replaceTrivia(old, new)`).

### 1.3 Canonical form

Trivia attach to tokens by grammar.md §1.6. The setters keep that convention:

- trailing trivia hold at most one `LineEnding`, and it is the last of them;
- a `Comment` is always followed by a `LineEnding`, except at the end of the input: as
  the last trivia of the leading trivia of `EndOfFile`, or of the trailing trivia of the
  last token before it;
- two `Whitespace` trivia never stand next to each other;
- a `ByteOrderMark` stands only as the first trivia of the first token;
- a trivia that ends with a lone carriage return (a comment or whitespace may) is not
  followed by a `LineEnding` `\n`, which would join it into one `\r\n`; an edit writes a
  `\r\n` there;
- `EndOfFile` has no trailing trivia.

A setter that would break the form fails at once, instead of shifting the structure
after printing.

### 1.4 Position

`Position { line, column, offset }` is an immutable value: `line` and `column` 1-based,
`offset` 0-based from the start of the input, all in Unicode code points. A host whose
strings are indexed otherwise may add a field of its own (JavaScript: `utf16Offset`).


## 2. Nodes

### 2.1 Classes and slots

Slots are named by their role, bracket pairs `open*` and `close*`, lists in the plural.

| node | slots | note |
|---|---|---|
| `DocumentNode` | `value: ValueNode \| null`, `endOfFile: Token` | the root; `value` is a block array or a single value |
| `BlockArrayNode` | `items: ArrayItemNode[]` | at least one item; the indentation is read from the items |
| `InlineArrayNode` | `openBracket: Token`, `items: ArrayItemNode[]`, `closeBracket: Token` | the bracket is `[`, `{` or `(`; may be empty |
| `ArrayItemNode` | `bullet: Token \| null`, `key: StringNode \| LiteralNode \| null`, `colon: Token \| null`, `value: ValueNode \| null`, `separator: Token \| null` | in a block: `bullet` or `key` and `colon`, `separator` null; inline: `bullet` null, `separator` the comma after the item or null |
| `EntityNode` | `value: ValueNode`, `attributes: InlineArrayNode \| null` | `attributes` null only for a link of a chain without parentheses |
| `EntityChainNode` | `entities: EntityNode[]` | two or more |
| `StringNode` | `token: Token` | |
| `LiteralNode` | `token: Token` | |

`colon` holds a `Colon` or an `Equals` token. A missing value is `null` in the slot,
never a node without tokens.

**`ValueNode`** is the common type of `BlockArrayNode`, `InlineArrayNode`, `EntityNode`,
`EntityChainNode`, `StringNode` and `LiteralNode`: the nodes that have a value
(`toValue()`). `DocumentNode` and `ArrayItemNode` are structural: the document
delegates `toValue()` to its value, and an item has no `toValue()` (an item is not a
value; the array builds its value from its items).

Shapes the grammar cannot produce are refused by the slots: an item of an inline array
or of attributes never holds a `BlockArrayNode`; the head of an entity is neither a
block nor a chain. A host expresses that in types where it can; a write that would
break it is refused before anything changes.

### 2.2 Invariants

1. Every token of the stream is in exactly one slot or list, in the order of the
   stream, `EndOfFile` included. The parser verifies it by identity and order after
   building the tree, as a check in the code, not only in a test.
2. `print(parse(s)) === s`.
3. The trivia keep their canonical form (§1.3) after every mutation.
4. Every node has a first and a last token.
5. A node or a token stands in at most one place. Writing a node that already has a
   parent into another slot fails (`detach()` or `clone()` first), and so does writing
   a node into its own subtree. `clone()` is deep over nodes and tokens; trivia may be
   shared.
6. The lists `items` and `entities` are read-only to the outside and change only
   through the methods of their node.
7. A mutation is atomic: a refused one leaves every object it touched as it was, the
   texts, trivia, parents and identities of the target and of a donor tree alike.

### 2.3 Who guards what

Each invariant has one guard, so that no write can go around it:

1. **A token** guards its text and the form of each of its trivia lists:
   `Token.fromText()` and `setText()` accept one token of the kind, in valid Unicode;
   the trivia setters accept a list in the canonical form as far as the list alone
   decides it (§1.3 without the rules of the place).
2. **The shape of a node** of §2.1 (the kinds of the tokens in the slots, the bracket
   pairs, a block with items, a chain with two links) and invariant 4 are guarded by
   where nodes come from. Nodes have no public constructors: a node comes from
   `parse()` or `clone()`, and it leaves a tree by `detach()` or `remove()`, so it
   always has the shape the parser or an edit gave it. Invariant 5 and the shapes
   that depend on the slot are guarded by the operations of point 4 when they write a
   node into a slot. What depends on the place in a document (where a byte order mark
   may stand, whether a comment may end the input, the indentation of a block) is not
   known in a detached subtree.
3. **An attached tree**, one under a `DocumentNode`: a trivia setter also refuses what
   the neighbours decide, a byte order mark anywhere but at the start of the input, a
   comment without a line ending anywhere but at its end, and a line ending or a
   comment in leading trivia that no line ending precedes (it belongs to the trailing
   trivia of the token before).
4. **The meaning**, that `parse(print(tree))` gives the same tree, is guarded by the
   operations of mutations.md: `setValue`, `setKey`, `addItem`, `insert`, `remove`,
   `replaceWith`, `detach`, and the public slot setters, which are the same operations.
   They move the trivia, reindent and write keys as the grammar needs. `setText()` and
   the trivia setters are the level beneath them: they keep the invariants above, but
   a new key may collide with a sibling and a new lexeme may change the value, which is
   what they are for.


## 3. Queries

Names are shared by the hosts that use methods (TypeScript, PHP, Python); Go and Rust
use their conventions (`FirstToken`, `first_token`, `Value` for `toValue`). `get*`
returns what exists, `find*` may return null, `is*` and `has*` are questions.

On every `Node`: `getChildren()` (the slots in order, without nulls), `getTokens()`,
`getFirstToken()`, `getLastToken()`, `getPosition()`, `print()`, `isMultiLine()`,
`hasLeadingComment()`, `getLeadingComments()`, `getTrailingComments()`,
`findAncestor(Class)`, `getNextSibling()`, `getPreviousSibling()`,
`find(Class, predicate?)`, `findFirst(Class, predicate?)`.

On every `ValueNode`: `toValue()`. `LiteralNode.toValue(isKey = false)`.

On `ArrayItemNode`: `getIndentation()`, `getKeyValue()` (the canonical key,
values.md §4), `getValue()` (the value of the item, null without one).

On `BlockArrayNode` and `InlineArrayNode`: `findItem(key)`.

On `DocumentNode`: `findItem(path)`, `toValue()`.

`find` searches by class, `findItem` by key or path, so that no name has two signatures
in any host.


## 4. Mutations

Their semantics is mutations.md; these are the names.

On every `Node`: `clone()`. On every `ValueNode`: `replaceWith(node)`, `detach()`.

On `ArrayItemNode`: `setValue(value | node)`, `setKey(key)`,
`remove(comments = CommentPolicy.Drop, mergeBlankLines = false)`; the property
`nullable`.

On `BlockArrayNode` and `InlineArrayNode`: `addItem(key?, value, position?)`,
`insert(position, item)`, `removeItem(item)`.

On `DocumentNode`: `setValue(path, value, {create})`, `addItem(path, key?, value)`,
`removeItem(path, {comments, nullable})`, `setKey(path, key)`; the property `nullable`.

`CommentPolicy`: `Drop`, `MoveToNextToken`.

`Traverser.traverse(node, enter?, leave?)`, where a visitor returns nothing, a node as
a replacement, or one of `TraverseAction`: `SkipChildren`, `Stop`, `Remove`. The actions are
values of their own type (an enum, a symbol), never numbers, so that a visitor cannot return
one by accident. The walk is depth-first over nodes, not tokens. The children of a node are
taken when it is entered, so nodes inserted around the visited one later are not visited, and
a child that no longer stands in the node when its turn comes is skipped; a node that a
visitor takes out of its place itself is neither walked into nor left, as one it removes by
`Remove`. A replacement returned by `enter` is visited from its children on; `Remove`
applies to items only (an item is removed with `remove()`), any other node is replaced.
`Stop` ends the walk at once: no node is entered or left after it.

**Rust** is the one named deviation of the model: its tree owns its children and has
no parents, so `replaceWith`, `findAncestor` and the sibling queries do not exist there,
and mutations go through paths and `&mut` parents. The semantics of mutations.md and
the corpus `mutations/` hold there as well.


## 5. Dump

The format of the corpora `cst/` and `roundtrip/`: one line per node or token,
indented by tabs; `slot: ` before the value of a slot, `- ` before an element of a list;
a token as `Kind "text"` with the text as a JSON string, its leading trivia after `<`
and its trailing trivia after `>`, each a list of `Kind "text"` in brackets separated
by `, `. Null slots are not written, empty lists are (`items: []`). Positions and
parents are not written.

`first: # c` + `\n` + `\t- a` + `\n`:

```
DocumentNode
	value: BlockArrayNode
		items:
			- ArrayItemNode
				key: LiteralNode
					token: Literal "first"
				colon: Colon ":" >[Whitespace " ", Comment "# c", LineEnding "\n"]
				value: BlockArrayNode
					items:
						- ArrayItemNode
							bullet: Dash "-" <[Whitespace "\t"] >[Whitespace " "]
							value: LiteralNode
								token: Literal "a" >[LineEnding "\n"]
	endOfFile: EndOfFile ""
```

The JSON string of a text escapes `"` and `\`, writes the control characters U+0000 to
U+001F as `\b`, `\f`, `\n`, `\r`, `\t` or `\u00xx` in lowercase hexadecimal, and
everything else, non-ASCII included, as it is.
