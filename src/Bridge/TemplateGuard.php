<?php

declare(strict_types=1);

namespace Lou\Bridge;

/**
 * Static check for PHP written by an LLM (templates, site/config.php). Template PHP runs on the server
 * at build time, so only a small, safe subset is allowed:
 *   - functions from an allowlist (string, array, math, date, escaping); no callables, no I/O
 *   - method/property access only on $v (the View helper) and on plain variables holding arrays/scalars
 *   - no eval, include/require, backticks, variable functions/variables, static calls, `new`, superglobals
 *
 * @return list<string> problems (empty = OK)
 */
final class TemplateGuard
{
    private const FUNCTIONS = [
        // strings
        'strlen', 'mb_strlen', 'substr', 'mb_substr', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper',
        'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'str_contains', 'str_starts_with', 'str_ends_with',
        'str_replace', 'str_pad', 'str_repeat', 'sprintf', 'number_format', 'nl2br', 'htmlspecialchars', 'strip_tags',
        'wordwrap', 'implode', 'explode', 'join', 'strpos', 'mb_strpos', 'str_word_count', 'strrev', 'sprintf',
        'urlencode', 'rawurlencode',
        // arrays
        'count', 'array_slice', 'array_chunk', 'array_keys', 'array_values', 'array_merge', 'array_reverse', 'in_array',
        'array_key_exists', 'array_key_first', 'array_key_last', 'array_column', 'array_combine', 'array_flip',
        'array_unique', 'array_sum', 'array_fill', 'range', 'min', 'max', 'is_array', 'is_string', 'is_int',
        'is_numeric', 'is_bool', 'isset', 'empty', 'sort', 'rsort', 'ksort', 'krsort', 'asort', 'arsort',
        // math / types / dates
        'round', 'floor', 'ceil', 'abs', 'intval', 'floatval', 'boolval', 'strval', 'date', 'gmdate', 'time', 'mktime',
        'checkdate',
        // language constructs that tokenize as calls
        'list', 'array', 'unset', 'print', 'echo',
    ];

    private const SUPERGLOBALS = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_FILES', '$_ENV', '$_REQUEST', '$_SESSION', '$GLOBALS'];

    /** @return list<string> */
    public static function check(string $code, string $file = 'template'): array
    {
        try {
            $tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return ["$file: PHP syntax error on line {$e->getLine()}: {$e->getMessage()}"];
        }
        $problems = [];
        $significant = array_values(array_filter($tokens, fn (\PhpToken $t) => !$t->isIgnorable()));
        $n = count($significant);
        for ($i = 0; $i < $n; $i++) {
            $t = $significant[$i];
            $prev = $significant[$i - 1] ?? null;
            $next = $significant[$i + 1] ?? null;
            $line = $t->line;
            $bad = static function (string $what) use (&$problems, $file, $line): void {
                $problems[] = "$file:$line: $what is not allowed in templates";
            };

            if ($t->is([T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_GOTO, T_HALT_COMPILER, T_NEW,
                T_GLOBAL, T_STATIC, T_FUNCTION, T_FN, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_DECLARE, T_NAMESPACE, T_USE,
                T_DOUBLE_COLON, T_EXIT, T_THROW, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $bad("'" . $t->text . "'");
            } elseif ($t->text === '`') {
                $bad('backtick shell execution');
            } elseif ($t->is(T_VARIABLE) && in_array($t->text, self::SUPERGLOBALS, true)) {
                $bad($t->text);
            } elseif ($t->text === '$' && $next !== null && ($next->is(T_VARIABLE) || $next->text === '{')) {
                $bad('variable variables');
            } elseif ($t->is(T_VARIABLE) && $next?->text === '(') {
                $bad('calling a variable as a function');
            } elseif ($t->text === '(' && $prev !== null && self::callsAValue($prev)) {
                // 'system'(), ('sys' . 'tem')(), $a[0](), "{$f}"(), $v->field('f')(): a call whose callee is a value
                $bad('calling a string, array element or expression as a function');
            } elseif ($t->is(T_ELLIPSIS) && $prev?->text === '(' && $next?->text === ')') {
                $bad('first-class callables (...)');
            } elseif ($t->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && $next?->text === '('
                && !($prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]))) {
                $name = strtolower(ltrim($t->text, '\\'));
                if (!in_array($name, self::FUNCTIONS, true)) {
                    $bad("function $name()");
                }
            } elseif ($t->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                // property/method access must hang off a chain rooted at $v
                if (!self::rootedAtView($significant, $i)) {
                    $bad('-> on anything other than $v');
                }
                if ($next !== null && !$next->is(T_STRING)) {
                    $bad('dynamic property or method names');
                }
            } elseif ($t->is(T_STRING) && in_array(strtolower($t->text), ['assert', 'create_function'], true)) {
                $bad($t->text);
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Whether a "(" right after $prev would call the value $prev ends (a string, an array element, the result of
     * an expression or call) rather than open a group or a named call. Named calls are checked by name elsewhere.
     */
    private static function callsAValue(\PhpToken $prev): bool
    {
        return in_array($prev->text, [')', ']', '}', '"'], true)
            || $prev->is([T_CONSTANT_ENCAPSED_STRING, T_END_HEREDOC, T_VARIABLE]);
    }

    /** Walk back over `->name`, `?->name`, `(...)` and `[...]` to find the chain's root variable. */
    private static function rootedAtView(array $tokens, int $i): bool
    {
        $j = $i - 1;
        while ($j >= 0) {
            $t = $tokens[$j];
            if ($t->text === ')' || $t->text === ']') {
                $depth = 0;
                for (; $j >= 0; $j--) {
                    $c = $tokens[$j]->text;
                    if ($c === ')' || $c === ']') {
                        $depth++;
                    } elseif ($c === '(' || $c === '[') {
                        if (--$depth === 0) {
                            break;
                        }
                    }
                }
                $j--;
                continue;
            }
            if ($t->is(T_STRING) && $j > 0 && $tokens[$j - 1]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $j -= 2;
                continue;
            }
            return $t->is(T_VARIABLE) && $t->text === '$v';
        }
        return false;
    }
}
