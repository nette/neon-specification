<?php declare(strict_types=1);

/**
 * Generates random NEON documents with the outcome of nette/neon 3.4 for each, as JSON lines
 * {seed, input, value | error}, for a differential run of an implementation.
 *
 *   php fuzz.php <count> [<seed>] > fuzz.jsonl
 *
 * Every document comes from its own seed, so a finding is reproduced by `php fuzz.php 1 <seed>`.
 */

require __DIR__ . '/php-oracle.php';


final class Fuzzer
{
	public const Version = 1;

	private const Words = [
		'a', 'b', 'key', 'foo bar', '@service', '%param%', 'x:y', '::', '-x', 'a#b', 'žluť', 'true', 'No', 'null', 'on', '0', '-1', '+1',
		'1.5', '.5', '-.5', '1e3', '0x1F', '0o17', '0b101', '0777', '1_000', '2016-06-03', '2016-06-03 19:00:00 +02:00', 'A\B', '*', '[x',
		'y]',
	];
	private const Noise = [
		' ', '  ', "\t", "\n", "\r\n", '#', '# c', ':', ': ', '-', '- ', ',', '[', ']', '{', '}', '(', ')', '=', "'", '"', "'''\n", 'x',
		"\n\t", "\n  ",
	];


	public function document(int $seed): string
	{
		mt_srand($seed);
		$doc = match (mt_rand(0, 2)) {
			0 => Nette\Neon\Neon::encode($this->value(3), (bool) mt_rand(0, 1), mt_rand(0, 1) ? "\t" : '  '),
			1, 2 => $this->block(0, mt_rand(0, 1) ? "\t" : '  ', ''),
		};
		return mt_rand(0, 2) ? $this->perturb($doc) : $doc;
	}


	private function value(int $depth): mixed
	{
		return match ($depth > 0 ? mt_rand(0, 9) : mt_rand(0, 5)) {
			0 => null,
			1 => (bool) mt_rand(0, 1),
			2 => mt_rand(-1000, 1000),
			3 => mt_rand(-1000, 1000) / 7,
			4 => self::Words[mt_rand(0, count(self::Words) - 1)],
			5 => mt_rand(0, 1) ? "line\n  indented\nlast" : "a'b\"c\t",
			6, 7 => array_map(fn() => $this->value($depth - 1), range(0, mt_rand(0, 3))),
			8 => array_combine(
				array_map(fn($i) => self::Words[mt_rand(0, 8)] . $i, range(0, $n = mt_rand(0, 3))),
				array_map(fn() => $this->value($depth - 1), range(0, $n)),
			),
			9 => new Nette\Neon\Entity(self::Words[mt_rand(0, 4)], array_map(fn() => $this->value($depth - 1), range(0, mt_rand(0, 2)))),
		};
	}


	private function block(int $depth, string $unit, string $indent): string
	{
		$res = '';
		$bullets = mt_rand(0, 1);
		for ($i = mt_rand(1, 4); $i > 0; $i--) {
			$res .= mt_rand(0, 4) ? '' : $indent . '# comment' . "\n";
			$res .= mt_rand(0, 5) ? '' : "\n";
			$prefix = $indent . ($bullets || !mt_rand(0, 5) ? '-' : $this->key() . (mt_rand(0, 5) ? ':' : ' ='));
			$res .= match ($depth < 3 ? mt_rand(0, 5) : mt_rand(0, 2)) {
				0 => $prefix . "\n",
				1, 2 => $prefix . ' ' . $this->inline(1) . (mt_rand(0, 3) ? '' : ' # c') . "\n",
				3 => $prefix . "\n" . $indent . $unit . $this->inline(1) . "\n",
				4 => $prefix . "\n" . $this->block($depth + 1, $unit, $indent . $unit),
				5 => $prefix . " '''\n" . $indent . $unit . "text\n" . $indent . $unit . "'''\n",
			};
		}
		return $res;
	}


	private function inline(int $depth): string
	{
		return match ($depth > 0 ? mt_rand(0, 8) : mt_rand(0, 3)) {
			0, 1, 2 => self::Words[mt_rand(0, count(self::Words) - 1)],
			3 => mt_rand(0, 1) ? "'q''uo'" : '"es\tcé"',
			4, 5 => '[' . implode(mt_rand(0, 1) ? ', ' : ",\n", array_map(fn() => $this->inline($depth - 1), range(0, mt_rand(0, 3)))) . ']',
			6 => '{' . implode(', ', array_map(fn($i) => $this->key() . $i . ': ' . $this->inline($depth - 1), range(0, mt_rand(0, 2)))) . '}',
			7 => self::Words[mt_rand(0, 4)] . '(' . implode(', ', array_map(fn() => $this->inline($depth - 1), range(0, mt_rand(0, 2)))) . ')',
			8 => 'a(1)b' . (mt_rand(0, 1) ? '(2)' : ''),
		};
	}


	private function key(): string
	{
		return ['a', 'b', 'key', "'q k'", '"d"', '5', '0x10', '1.5', 'true', 'x-y'][mt_rand(0, 9)];
	}


	/** Inserts, deletes or duplicates small pieces at random places. */
	private function perturb(string $doc): string
	{
		for ($n = mt_rand(1, 3); $n > 0; $n--) {
			$pos = mt_rand(0, strlen($doc));
			while ($pos > 0 && $pos < strlen($doc) && (ord($doc[$pos]) & 0xC0) === 0x80) {
				$pos--; // keep multibyte characters whole
			}
			$doc = match (mt_rand(0, 2)) {
				0 => substr($doc, 0, $pos) . self::Noise[mt_rand(0, count(self::Noise) - 1)] . substr($doc, $pos),
				1 => substr($doc, 0, $pos) . substr($doc, min(strlen($doc), $pos + 1)),
				2 => $doc . "\n" . substr($doc, $pos, mt_rand(0, 20)),
			};
		}
		return preg_match('##u', $doc) ? $doc : mb_convert_encoding($doc, 'UTF-8', 'UTF-8');
	}
}


if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	$count = (int) ($argv[1] ?? 1000);
	$first = (int) ($argv[2] ?? random_int(1, 1 << 30));
	$fuzzer = new Fuzzer;
	$oracle = new Oracle('', true);
	for ($seed = $first; $seed < $first + $count; $seed++) {
		$input = $fuzzer->document($seed);
		$outcome = $oracle->outcome($input);
		if (isset($outcome['deviation'])) {
			continue; // 3.x cannot express it in the terms of the specification
		}
		echo json_encode(['seed' => $seed, 'generator' => Fuzzer::Version, 'input' => $input] + $outcome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), "\n";
	}
}
