<?php

declare(strict_types=1);

namespace Lou\Support;

/**
 * Optional history layer: commits site/ after an approved changeset when config 'git' => ['commit' => true]
 * and the install root is a Git checkout. Runs git without a shell (argument array), never pushes.
 */
final class Git
{
    public function __construct(private readonly string $root)
    {
    }

    public static function available(string $root): bool
    {
        return file_exists($root . '/.git') && function_exists('proc_open');
    }

    /** @return string|null the new commit hash, or null when there was nothing to commit */
    public function commit(string $message, string $author): ?string
    {
        $this->run(['add', '-A', '--', 'site']);
        if ($this->run(['status', '--porcelain', '--', 'site'], false)[1] === '') {
            return null;
        }
        $this->run(['-c', 'user.name=Lou', '-c', 'user.email=lou@localhost', 'commit', '-q', '-m', $message,
            '--author=' . preg_replace('/[<>\r\n]/', '', $author) . ' <lou@localhost>', '--', 'site']);
        return trim($this->run(['rev-parse', '--short', 'HEAD'])[1]);
    }

    /**
     * Fetches the upstream of the checked-out branch and fast-forwards to it.
     * @return bool true when HEAD moved (there was something new)
     */
    public function pullFastForward(): bool
    {
        $this->run(['fetch', '--quiet']);
        $head = $this->run(['rev-parse', 'HEAD'])[1];
        $upstream = $this->run(['rev-parse', '@{u}'])[1];
        if ($head === $upstream) {
            return false;
        }
        $this->run(['merge', '--ff-only', '--quiet', '@{u}']);
        return true;
    }

    /** Top folder of the working tree that contains $root (e.g. the client repo around a site/ folder). */
    public function topLevel(): string
    {
        return $this->run(['rev-parse', '--show-toplevel'])[1];
    }

    /** Absolute path of the .git folder (works for submodules and worktrees, where .git is a file). */
    public function gitDir(): string
    {
        return $this->run(['rev-parse', '--absolute-git-dir'])[1];
    }

    /** Checks out the commits the superproject pins for its submodules (e.g. the Lou version of a client site). */
    public function updateSubmodules(): void
    {
        $this->run(['submodule', 'update', '--init', '--recursive', '--quiet']);
    }

    /** @return array{0: int, 1: string} */
    private function run(array $args, bool $check = true): array
    {
        $proc = proc_open(['git', '-C', $this->root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \Lou\LouException('Could not run git');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($check && $code !== 0) {
            throw new \Lou\LouException('git ' . $args[0] . ' failed: ' . trim($err ?: $out));
        }
        return [$code, trim($out)];
    }
}
