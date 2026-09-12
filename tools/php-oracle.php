<?php declare(strict_types=1);

/**
 * Generates and checks the expectations of the conformance corpus from nette/neon 3.4,
 * the historical baseline of the format.
 *
 *   php php-oracle.php generate [<corpus>]   writes the expectations of every case
 *   php php-oracle.php check [<corpus>]      verifies them, exit code 1 on any difference
 *   php php-oracle.php selfcheck             verifies that check fails on each kind of broken corpus
 *
 * A case whose sidecar classifies the deviation from 3.x as `fixed` or `host` keeps its
 * hand-written expectation; `check` verifies that the oracle still disagrees with it.
 */

use Nette\Neon;
use Nette\Neon\Node;

require __DIR__ . '/vendor/autoload.php';

ini_set('serialize_precision', '-1');
ini_set('precision', '14');
date_default_timezone_set('UTC');

if (PHP_INT_SIZE !== 8 || PHP_VERSION_ID < 80400 || !extension_loaded('bcmath')) {
	fwrite(STDERR, "The oracle requires 64-bit PHP 8.4+ with bcmath.\n");
	exit(2);
}


final class Oracle
{
	private const Categories = ['decode', 'errors', 'encode', 'roundtrip', 'cst'];
	private const Deviations = ['fixed', 'host'];

	/** One well-formed UTF-8 sequence, matched on bytes (a pattern with /u fails on the whole invalid subject). */
	private const Utf8Char = '~[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
		. '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}~A';

	/** @var list<string> */
	public array $failures = [];
	public int $written = 0;
	public int $checked = 0;


	public function __construct(
		private readonly string $corpus,
		private readonly bool $checkOnly,
	) {
	}


	public function run(): void
	{
		$this->validateStructure();
		if ($this->failures) {
			return; // a corpus that cannot be trusted is not checked at all, so it never looks green
		}

		foreach (self::Categories as $category) {
			$dir = "$this->corpus/$category";
			$inputs = glob($dir . ($category === 'encode' ? '/*.json' : '/*.neon')) ?: [];
			$inputs = array_filter($inputs, fn($f) => !preg_match('~\.(case|block)\.\w+$~', $f));
			foreach ($inputs as $input) {
				$name = preg_replace('~\.\w+$~', '', basename($input));
				try {
					$this->runCase($category, "$dir/$name", file_get_contents($input));
				} catch (CaseFailure $e) {
					$this->failures[] = "$category/$name: {$e->getMessage()}";
				}
			}
		}

		if (!$this->checkOnly) {
			file_put_contents("$this->corpus/oracle.json", self::encodeJson(self::environment()));
		} elseif (!is_file("$this->corpus/oracle.json")) {
			$this->failures[] = 'oracle.json: missing';
		} elseif (json_decode(file_get_contents("$this->corpus/oracle.json"), true) !== self::environment()) {
			$this->failures[] = 'oracle.json: the expectations were generated in a different environment';
		}
	}


	/**
	 * Fails closed: every category exists and has cases, every file belongs to a case with its input,
	 * and every sidecar has only known fields (corpus/readme.md).
	 */
	private function validateStructure(): void
	{
		$layout = [
			'decode' => ['.neon', '~^\.json$~'],
			'errors' => ['.neon', '~^\.error$~'],
			'encode' => ['.json', '~^\.(neon|block\.neon|error)$~'],
			'cst' => ['.neon', '~^\.dump$~'],
			'roundtrip' => ['.neon', '~^\.dump$~'],
			'mutations' => ['.neon', '~^\.(ops\.json|expected(\.\d+)?\.(neon|json))$~'],
		];
		if (!is_file("$this->corpus/../spec/VERSION")) {
			$this->failures[] = 'spec/VERSION: missing next to the corpus';
		}
		foreach ($layout as $category => [$input, $allowed]) {
			$dir = "$this->corpus/$category";
			$files = is_dir($dir) ? array_diff(scandir($dir), ['.', '..']) : [];
			$names = [];
			foreach ($files as $file) {
				$name = strstr($file, '.', true) ?: $file;
				if ($name . $input === $file) {
					$names[$name] = true;
				}
			}
			if (!$names) {
				$this->failures[] = "$category: missing or empty";
			}
			foreach ($files as $file) {
				$name = strstr($file, '.', true) ?: $file;
				$ext = substr($file, strlen($name));
				if ($ext !== $input && $ext !== '.case.json' && !preg_match($allowed, $ext)) {
					$this->failures[] = "$category/$file: unknown file";
				} elseif (!isset($names[$name])) {
					$this->failures[] = "$category/$file: an expectation without its input";
				} elseif ($ext === '.case.json') {
					$meta = json_decode(file_get_contents("$dir/$file"), true) ?? [];
					$unknown = array_diff(array_keys($meta), ['schema', 'hosts', 'php3', 'reason', 'indentation', 'blockMode']);
					$hosts = array_diff($meta['hosts'] ?? [], ['js', 'php', 'py', 'go', 'rs']);
					if ($unknown || $hosts) {
						$this->failures[] = "$category/$file: unknown " . implode(', ', [...$unknown, ...$hosts]);
					}
				}
			}
		}
	}


	private function runCase(string $category, string $base, string $input): void
	{
		$meta = is_file("$base.case.json")
			? json_decode(file_get_contents("$base.case.json"), true, flags: JSON_THROW_ON_ERROR)
			: [];
		$deviation = $meta['php3'] ?? null;
		if ($meta && ($meta['schema'] ?? null) !== 1) {
			throw new CaseFailure('unknown schema of the sidecar');
		} elseif ((isset($meta['hosts']) || $deviation !== null) && !isset($meta['reason'])) {
			throw new CaseFailure('the sidecar lacks a reason');
		} elseif ($deviation !== null && !in_array($deviation, self::Deviations, true)) {
			throw new CaseFailure("deviation '$deviation' is not decided");
		} elseif (isset($meta['hosts']) && !in_array('php', $meta['hosts'], true)) {
			return;
		}

		try {
			$expected = match ($category) {
				'decode' => ['json' => self::encodeJson($this->decode($input, $deviation !== null))],
				'errors' => ['error' => $this->error($input, $deviation !== null)],
				'encode' => $this->encode($input, $meta['indentation'] ?? "\t", $deviation !== null),
				'roundtrip', 'cst' => $this->accept($input),
			};
		} catch (CaseFailure $e) {
			if ($deviation === null) {
				throw $e;
			} elseif (!glob("$base.{json,error,neon}", GLOB_BRACE)) { // 3.x disagrees by failing differently
				throw new CaseFailure('the deviating case lacks its hand-written expectation');
			}
			$this->checked++;
			return;
		}

		if (isset($meta['blockMode']) && $meta['blockMode'] !== true) { // 3.x has no depth of blocks
			unset($expected['block.neon']);
			if (!is_file("$base.block.neon")) {
				throw new CaseFailure('a case with blockMode needs its hand-written block.neon');
			}
		}

		$agreements = 0;
		foreach ($expected as $ext => $content) {
			$file = "$base.$ext";
			$current = is_file($file) ? file_get_contents($file) : null;
			if ($deviation !== null) {
				if ($current === null) {
					throw new CaseFailure("the deviating case lacks its hand-written $ext");
				} elseif ($current === $content && ++$agreements === count($expected)) {
					throw new CaseFailure("classified as '$deviation', but 3.x agrees");
				}
			} elseif ($this->checkOnly) {
				if ($current !== $content) {
					throw new CaseFailure("$ext differs from the oracle:\n" . $content);
				}
			} elseif ($current !== $content) {
				file_put_contents($file, $content);
				$this->written++;
			}
			$this->checked++;
		}

		if ($category === 'encode' && $deviation === null) { // an encoded value or an error, never both
			foreach (['error', 'neon', 'block.neon'] as $ext) {
				if (isset($expected[$ext]) || !is_file("$base.$ext") || ($ext === 'block.neon' && isset($meta['blockMode']))) {
					continue;
				} elseif ($this->checkOnly) {
					throw new CaseFailure("stale $ext");
				}
				unlink("$base.$ext");
			}
		}
	}


	/**
	 * What 3.x makes of any input, in the terms of the specification: the typed value, or the error
	 * with its position. Used by differential runs and the fuzzer.
	 * @return array{value: array<string, mixed>}|array{error: string}|array{deviation: string}
	 */
	public function outcome(string $input): array
	{
		try {
			return ['value' => $this->decode($input, false)];
		} catch (CaseFailure $e) {
			if (!str_starts_with($e->getMessage(), '3.x rejects')) {
				return ['deviation' => $e->getMessage()];
			}
		}

		try {
			return ['error' => $this->error($input, false)];
		} catch (CaseFailure $e) {
			return ['deviation' => $e->getMessage()];
		}
	}


	/** @return array<string, mixed> */
	private function decode(string $input, bool $deviating): array
	{
		if (!$deviating && preg_match("~\r(?!\n)~", $input)) {
			$spaced = preg_replace("~\r(?!\n)~", ' ', $input);
			try {
				$same = self::sameValue(Neon\Neon::decode($input), Neon\Neon::decode($spaced));
			} catch (Throwable) {
				$same = false;
			}
			if (!$same) {
				throw new CaseFailure('a lone CR changes the value here, so the case needs a sidecar (3.x strips it, the specification reads it as a space)');
			}
			$deviating = true;
		}

		$input = $this->normalizeInput($input, $deviating);
		try {
			$stream = (new Neon\Lexer)->tokenize($input);
			$node = (new Neon\Parser)->parse($stream);
			$typed = $this->typedFromNode($node, $stream->getTokens());
			$value = Neon\Neon::decode($input);
		} catch (Throwable $e) {
			throw new CaseFailure('3.x rejects the input: ' . $e->getMessage());
		}

		if (!self::sameValue(self::toPhp($typed), $value)) {
			throw new CaseFailure('the typed value does not project back to the value of 3.x');
		}

		return $typed;
	}


	private function error(string $input, bool $deviating): string
	{
		if (!preg_match('##u', $input)) {
			return "Invalid UTF-8 sequence.\n" . self::invalidUtf8Position($input) . "\n";
		}

		$bom = str_starts_with($input, "\u{FEFF}");
		$input = $this->normalizeInput($input, $deviating);

		try {
			Neon\Neon::decode($input);
		} catch (Neon\Exception $e) {
			if (!preg_match('~^(.*) on line (\d+) at column (\d+)$~sD', $e->getMessage(), $m)) {
				throw new CaseFailure('message without position: ' . $e->getMessage());
			}
			[, $message, $line, $byteColumn] = $m;
			$lines = explode("\n", str_replace("\r", '', $input));
			$prefix = substr($lines[$line - 1] ?? '', 0, $byteColumn - 1);
			$column = mb_strlen($prefix) + 1;
			$message = $this->neutralMessage($message, $input, (int) $line, $column);
			$column += $line === '1' && $bom; // the byte order mark is a code point of the first line
			return "$message\n$line:$column\n";
		} catch (Throwable $e) {
			throw new CaseFailure('3.x fails with a foreign exception ' . $e::class . ': ' . $e->getMessage());
		}

		throw new CaseFailure('3.x accepts the input');
	}


	/** @return array<string, string> */
	private function encode(string $json, string $indentation, bool $deviating): array
	{
		$typed = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		if (!$deviating && self::containsLocalDate($typed)) {
			throw new CaseFailure('a date without offset needs a sidecar (3.x adds the zone of the server)');
		}

		$value = self::toPhp($typed);
		try {
			return [
				'neon' => Neon\Neon::encode($value, false, $indentation),
				'block.neon' => Neon\Neon::encode($value, true, $indentation),
			];
		} catch (Neon\Exception $e) {
			return ['error' => $e->getMessage() . "\n"];
		}
	}


	/**
	 * A document of the formatting zoo has no expectation of its own, 3.x only has to accept it.
	 * @return array{}
	 */
	private function accept(string $input): array
	{
		try {
			Neon\Neon::decode($this->normalizeInput(str_replace("\r", '', $input), false));
		} catch (Throwable $e) {
			throw new CaseFailure('3.x rejects the input: ' . $e->getMessage());
		}

		$this->checked++;
		return [];
	}


	/** BOM is trivia in the specification; 3.x accepts it only in decodeFile(). */
	private function normalizeInput(string $input, bool $deviating): string
	{
		if (!$deviating && preg_match("~\r(?!\n)~", $input)) {
			throw new CaseFailure('a lone CR needs a sidecar (3.x strips it before lexing)');
		}

		return str_starts_with($input, "\u{FEFF}") ? substr($input, 3) : $input;
	}


	/** Text of 'Unexpected' is cut to 40 code points, line endings shown as <new line>. */
	private function neutralMessage(string $message, string $input, int $line, int $column): string
	{
		if (!preg_match("~^Unexpected '(.*)'$~sD", $message, $m)) {
			return $message;
		}

		$lines = explode("\n", str_replace("\r", '', $input));
		$rest = mb_substr(implode("\n", array_slice($lines, $line - 1)), $column - 1);
		if ($m[1] === str_replace("\n", '\n', substr($rest, 0, 40))) { // the scanner quotes the rest of the input
			return "Unexpected '" . str_replace("\n", '<new line>', mb_substr($rest, 0, 40)) . "'";
		} elseif (strlen($m[1]) >= 40 || !preg_match('##u', $m[1])) {
			throw new CaseFailure('the quoted token is cut, its code point form is unknown: ' . $message);
		}

		return $message;
	}


	private static function invalidUtf8Position(string $input): string
	{
		$line = 1;
		$column = 1;
		for ($i = 0; $i < strlen($input);) {
			if (!preg_match(self::Utf8Char, $input, $m, 0, $i)) {
				break;
			}
			$i += strlen($m[0]);
			[$line, $column] = $m[0] === "\n" ? [$line + 1, 1] : [$line, $column + 1];
		}

		return "$line:$column";
	}


	/**
	 * @param  list<Neon\Token>  $tokens
	 * @return array<string, mixed>
	 */
	private function typedFromNode(Node $node, array $tokens): array
	{
		return match (true) {
			$node instanceof Node\StringNode => ['type' => 'string', 'value' => $node->value],
			$node instanceof Node\LiteralNode => self::typedFromLiteral($node->value, $node->value === null ? '' : $tokens[$node->startTokenPos]->value),
			$node instanceof Node\InlineArrayNode => $this->typedFromItems($node->items, $tokens, $node->bracket === '{'),
			$node instanceof Node\BlockArrayNode => $this->typedFromItems($node->items, $tokens, false),
			$node instanceof Node\EntityNode => [
				'type' => 'entity',
				'value' => $this->typedFromNode($node->value, $tokens),
				'attributes' => $this->typedFromItems($node->attributes, $tokens, false),
			],
			$node instanceof Node\EntityChainNode => [
				'type' => 'chain',
				'entities' => array_map(fn($entity) => $this->typedFromNode($entity, $tokens), $node->chain),
			],
		};
	}


	/** @return array<string, mixed> */
	private static function typedFromLiteral(mixed $value, string $text): array
	{
		return match (true) {
			$value === null => ['type' => 'null'],
			is_bool($value) => ['type' => 'bool', 'value' => $value],
			is_int($value) => ['type' => 'int', 'value' => (string) $value],
			is_float($value) => ['type' => 'float', 'value' => self::formatFloat($value)],
			$value instanceof DateTimeInterface => ['type' => 'date', 'value' => $text],
			(bool) preg_match('~^[+-]?\d+$~D', $text) => ['type' => 'int', 'value' => self::canonicalInt($text)],
			(bool) preg_match('~^(0x[0-9a-fA-F]+|0o[0-7]+|0b[01]+)$~D', $text) => ['type' => 'int', 'value' => $value],
			default => ['type' => 'string', 'value' => $value],
		};
	}


	/**
	 * Builds the array with the semantics of PHP (implicit keys, overwriting) and types it.
	 * @param  list<Node\ArrayItemNode>  $items
	 * @param  list<Neon\Token>  $tokens
	 * @return array<string, mixed>
	 */
	private function typedFromItems(array $items, array $tokens, bool $emptyIsMap): array
	{
		$res = [];
		foreach ($items as $item) {
			$typed = $this->typedFromNode($item->value, $tokens);
			if ($item->key === null) {
				$res[] = $typed;
			} else {
				$res[(string) $item->key->value] = $typed;
			}
		}

		return self::typedArray($res, $emptyIsMap);
	}


	/**
	 * @param  array<int|string, array<string, mixed>>  $arr
	 * @return array<string, mixed>
	 */
	private static function typedArray(array $arr, bool $emptyIsMap): array
	{
		if ($arr ? array_is_list($arr) : !$emptyIsMap) {
			return ['type' => 'list', 'items' => $arr];
		}

		$entries = [];
		foreach ($arr as $k => $v) {
			$entries[] = [(string) $k, $v];
		}

		return ['type' => 'map', 'entries' => $entries];
	}


	/** Typed value of a PHP value, used when importing cases of encode. */
	public static function typedFromPhp(mixed $value): array
	{
		return match (true) {
			$value === null => ['type' => 'null'],
			is_bool($value) => ['type' => 'bool', 'value' => $value],
			is_int($value) => ['type' => 'int', 'value' => (string) $value],
			is_float($value) => ['type' => 'float', 'value' => self::formatFloat($value)],
			is_string($value) => ['type' => 'string', 'value' => $value],
			$value instanceof DateTimeInterface => ['type' => 'date', 'value' => $value->format('Y-m-d H:i:s' . ($value->format('u') === '000000' ? '' : '.u') . ' P')],
			$value instanceof Neon\Entity && $value->value === Neon\Neon::Chain => [
				'type' => 'chain',
				'entities' => array_map(self::typedFromPhp(...), array_values($value->attributes)),
			],
			$value instanceof Neon\Entity => [
				'type' => 'entity',
				'value' => self::typedFromPhp($value->value),
				'attributes' => self::typedArray(array_map(self::typedFromPhp(...), $value->attributes), false),
			],
			is_array($value) => self::typedArray(array_map(self::typedFromPhp(...), $value), false),
			$value instanceof stdClass => self::typedArray(array_map(self::typedFromPhp(...), (array) $value), true),
		};
	}


	/** PHP projection of a typed value. */
	public static function toPhp(array $typed): mixed
	{
		return match ($typed['type']) {
			'null' => null,
			'bool' => $typed['value'],
			'int' => self::intToPhp($typed['value']),
			'float' => match ($typed['value']) {
				'INF' => INF,
				'-INF' => -INF,
				'NAN' => NAN,
				default => (float) $typed['value'],
			},
			'string' => $typed['value'],
			'date' => new DateTimeImmutable($typed['value']),
			'entity' => new Neon\Entity(self::toPhp($typed['value']), self::toPhp($typed['attributes'])),
			'chain' => new Neon\Entity(Neon\Neon::Chain, array_map(self::toPhp(...), $typed['entities'])),
			'list' => array_map(self::toPhp(...), $typed['items']),
			'map' => self::mapToPhp($typed['entries']),
		};
	}


	private static function intToPhp(string $digits): int|string
	{
		return is_int($num = $digits * 1) ? $num : $digits;
	}


	private static function mapToPhp(array $entries): array|stdClass
	{
		$res = [];
		foreach ($entries as [$k, $v]) {
			$res[$k] = self::toPhp($v);
		}

		if ($res && array_is_list($res)) {
			throw new CaseFailure('a map with keys 0..n-1 must be written as a list');
		}

		return $res ?: new stdClass;
	}


	private static function sameValue(mixed $a, mixed $b): bool
	{
		if ($a instanceof stdClass && $b === []) { // PHP decodes {} as an array
			return true;
		} elseif ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
			return $a->format('Y-m-d H:i:s.u P') === $b->format('Y-m-d H:i:s.u P');
		} elseif ($a instanceof Neon\Entity && $b instanceof Neon\Entity) {
			return self::sameValue($a->value, $b->value) && self::sameValue($a->attributes, $b->attributes);
		} elseif (is_array($a) && is_array($b)) {
			if (array_keys($a) !== array_keys($b)) {
				return false;
			}
			foreach ($a as $k => $v) {
				if (!self::sameValue($v, $b[$k])) {
					return false;
				}
			}
			return true;
		} elseif (is_float($a) && is_float($b)) {
			return self::formatFloat($a) === self::formatFloat($b);
		} elseif (is_string($a) && is_string($b) && preg_match('~^[+-]?\d+$~D', $a) && preg_match('~^[+-]?\d+$~D', $b)) {
			return self::canonicalInt($a) === self::canonicalInt($b); // 3.x keeps the text of integers beyond PHP_INT_MAX
		}

		return $a === $b;
	}


	private static function canonicalInt(string $digits): string
	{
		$sign = $digits[0] === '-' ? '-' : '';
		$digits = ltrim($digits, '+-0');
		return $digits === '' ? '0' : $sign . $digits;
	}


	private static function containsLocalDate(array $typed): bool
	{
		if ($typed['type'] === 'date') {
			return !preg_match('~(Z|[+-]\d\d?(:?\d\d)?)$~D', $typed['value']);
		}

		foreach ($typed['items'] ?? $typed['entities'] ?? [] as $item) {
			if (self::containsLocalDate($item)) {
				return true;
			}
		}

		foreach ($typed['entries'] ?? [] as [, $item]) {
			if (self::containsLocalDate($item)) {
				return true;
			}
		}

		return (isset($typed['value']) && is_array($typed['value']) && self::containsLocalDate($typed['value']))
			|| (isset($typed['attributes']) && self::containsLocalDate($typed['attributes']));
	}


	/** Shortest round-trip form. */
	private static function formatFloat(float $value): string
	{
		return match (true) {
			is_nan($value) => 'NAN',
			is_infinite($value) => $value > 0 ? 'INF' : '-INF',
			default => json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
		};
	}


	/** @return array<string, string> */
	private static function environment(): array
	{
		return [
			'nette/neon' => Composer\InstalledVersions::getPrettyVersion('nette/neon'),
			'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'serialize_precision' => ini_get('serialize_precision'),
			'precision' => ini_get('precision'),
			'date.timezone' => date_default_timezone_get(),
		];
	}


	/** JSON indented by tabs, with a line ending at the end. */
	public static function encodeJson(array $data): string
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
		return preg_replace_callback('~^(?:    )+~m', fn($m) => str_repeat("\t", strlen($m[0]) / 4), $json) . "\n";
	}
}


final class CaseFailure extends Exception
{
}


function copyTree(string $from, string $to): void
{
	if (!is_dir($to)) {
		mkdir($to, recursive: true);
	}
	foreach (array_diff(scandir($from), ['.', '..']) as $entry) {
		is_dir("$from/$entry") ? copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
	}
}


function removeTree(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}
	foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
		is_dir("$dir/$entry") ? removeTree("$dir/$entry") : unlink("$dir/$entry");
	}
	rmdir($dir);
}


if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	$command = $argv[1] ?? null;
	if (!in_array($command, ['generate', 'check', 'selfcheck', 'outcomes'], true)) {
		fwrite(STDERR, "Usage: php php-oracle.php generate|check [<corpus>]\n       php php-oracle.php selfcheck\n       php php-oracle.php outcomes <dir>... > outcomes.jsonl\n");
		exit(2);
	}

	if ($command === 'outcomes') { // the outcome of 3.x for every .neon file below the directories, as JSON lines
		$oracle = new Oracle('', true);
		foreach (array_slice($argv, 2) as $dir) {
			$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				fn($f) => !in_array($f->getFilename(), ['vendor', 'node_modules'], true) && !str_starts_with($f->getFilename(), '.'),
			));
			foreach ($files as $file) {
				if ($file->isFile() && str_ends_with($file->getFilename(), '.neon')) {
					$path = str_replace('\\', '/', $file->getPathname());
					echo json_encode(['file' => $path] + $oracle->outcome(file_get_contents($path)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION), "\n";
				}
			}
		}
		exit(0);
	}

	if ($command === 'selfcheck') { // check must fail on each kind of broken corpus; a check that cannot fail proves nothing
		$source = __DIR__ . '/..';
		$breakages = [
			'empty corpus' => fn($c) => array_map(fn($d) => removeTree("$c/$d"), ['decode', 'errors', 'encode', 'cst', 'roundtrip', 'mutations']),
			'missing category' => fn($c) => removeTree("$c/cst"),
			'missing expectation' => fn($c) => unlink("$c/decode/basic-001.json"),
			'orphan expectation' => fn($c) => file_put_contents("$c/decode/no-such-input.json", '{}'),
			'undecided deviation' => fn($c) => file_put_contents("$c/decode/basic-001.case.json", '{"schema": 1, "php3": "open", "reason": "x"}'),
			'unknown field' => fn($c) => file_put_contents("$c/decode/basic-001.case.json", '{"schema": 1, "extra": 1}'),
			'broken expectation' => fn($c) => file_put_contents("$c/decode/basic-001.json", '{"type": "null"}'),
			'missing oracle.json' => fn($c) => unlink("$c/oracle.json"),
		];
		$passed = true;
		foreach ($breakages as $label => $break) {
			$dir = sys_get_temp_dir() . '/neon-oracle-' . getmypid();
			copyTree("$source/corpus", "$dir/corpus");
			copyTree("$source/spec", "$dir/spec");
			$break("$dir/corpus");
			$oracle = new Oracle("$dir/corpus", true);
			$oracle->run();
			removeTree($dir);
			echo ($oracle->failures ? 'ok   ' : 'MISS ') . "$label\n";
			$passed = $passed && $oracle->failures;
		}
		exit($passed ? 0 : 1);
	}

	$oracle = new Oracle(rtrim($argv[2] ?? __DIR__ . '/../corpus', '/\\'), $command === 'check');
	$oracle->run();
	foreach ($oracle->failures as $failure) {
		echo "FAIL $failure\n";
	}

	echo "$oracle->checked expectations, $oracle->written written, " . count($oracle->failures) . " failures\n";
	exit($oracle->failures ? 1 : 0);
}
