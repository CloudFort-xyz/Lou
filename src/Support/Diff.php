<?php

declare(strict_types=1);

namespace Lou\Support;

/** Minimal line diff (LCS) producing unified-style hunks, for reviewing LLM changes. */
final class Diff
{
    private const MAX_CELLS = 4_000_000;

    public static function unified(string $old, string $new, int $context = 3): string
    {
        $a = $old === '' ? [] : explode("\n", rtrim($old, "\n"));
        $b = $new === '' ? [] : explode("\n", rtrim($new, "\n"));
        // trim common prefix/suffix to keep the table small
        $pre = 0;
        while ($pre < count($a) && $pre < count($b) && $a[$pre] === $b[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < count($a) - $pre && $suf < count($b) - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) {
            $suf++;
        }
        $ma = array_slice($a, $pre, count($a) - $pre - $suf);
        $mb = array_slice($b, $pre, count($b) - $pre - $suf);
        if ((count($ma) + 1) * (count($mb) + 1) > self::MAX_CELLS) {
            return "(file too large to diff: " . count($a) . " → " . count($b) . " lines)\n";
        }
        $ops = [];
        for ($i = 0; $i < $pre; $i++) {
            $ops[] = [' ', $a[$i]];
        }
        foreach (self::lcsOps($ma, $mb) as $op) {
            $ops[] = $op;
        }
        for ($i = count($a) - $suf; $i < count($a); $i++) {
            $ops[] = [' ', $a[$i]];
        }
        // keep only changed lines with context
        $keep = [];
        foreach ($ops as $k => [$type]) {
            if ($type !== ' ') {
                for ($c = max(0, $k - $context); $c <= min(count($ops) - 1, $k + $context); $c++) {
                    $keep[$c] = true;
                }
            }
        }
        $out = '';
        $last = -2;
        $oldNo = $newNo = 1;
        foreach ($ops as $k => [$type, $line]) {
            if (isset($keep[$k])) {
                if ($k !== $last + 1) {
                    $out .= "@@ -$oldNo +$newNo @@\n"; // start lines of the hunk in the old and new file
                }
                $out .= $type . $line . "\n";
                $last = $k;
            }
            $oldNo += $type === '+' ? 0 : 1;
            $newNo += $type === '-' ? 0 : 1;
        }
        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }
        return $ops;
    }
}
