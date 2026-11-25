<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\App;
use Lou\Bridge\PathPolicy;
use Lou\Content\Workspace;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Deploys a client site's design from an uploaded zip (Admin → Updates → Site design): the fallback for when
 * the server can't pull the client repository. Same rule as `lou deploy --site` (Deployer::planDesign):
 * templates and assets follow the zip unless they were changed on the server since the last deploy; content,
 * menu, config and editor uploads are never touched.
 *
 * Unlike `deploy --site`, nothing goes live directly: the changes land in the uploader's draft, so they are
 * submitted, previewed and published through Review like any other change. Template PHP is checked by the
 * same guard as assistants' templates. What the import wrote is kept in var/deploy-design.pending.json and
 * only enters var/deploy-design.json once it is live (confirm(), called on publish), so a rejected import
 * never makes later deploys think the server changed those files.
 */
final class DesignImport
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Without any record of an earlier deploy (a site that started from a starter), the server's current files
     * count as the baseline, so every file that differs from the zip is proposed: Review shows the diffs.
     * @return array{written: list<string>, deleted: list<string>, skipped: list<string>, rejected: list<string>, first: bool}
     *         paths relative to site/; rejected: "path: reason"
     */
    public function toDraft(string $zipFile, Workspace $draft): array
    {
        $scratch = $this->app->varDir . '/.design-' . bin2hex(random_bytes(4));
        try {
            SiteArchive::extract($zipFile, "$scratch/site", $scratch);
            $from = "$scratch/site";
            $first = !is_file($this->app->varDir . '/deploy-design.json');
            $plan = Deployer::planDesign($from, $this->app->siteDir, $first ? Deployer::designHashes($this->app->siteDir) : $this->manifest());
            $draft->open();
            $dir = $draft->siteDir();
            $out = ['written' => [], 'deleted' => [], 'skipped' => $plan['skipped'], 'rejected' => [], 'first' => $first];
            $pending = $this->pending();
            foreach ($plan['write'] as $rel) {
                $problems = $this->check($rel, "$from/$rel");
                if ($problems) {
                    $out['rejected'][] = "$rel: " . implode('; ', $problems);
                    continue;
                }
                Fs::ensureDir(dirname("$dir/$rel"));
                if (!copy("$from/$rel", "$dir/$rel")) {
                    throw new LouException("Cannot write site/$rel to your draft");
                }
                $out['written'][] = $rel;
                $pending[$rel] = (string) sha1_file("$from/$rel");
            }
            // Without a record of an earlier deploy, a file that is only on the server was put there on the
            // server (an assistant, FTP), not by an earlier zip: keep it rather than delete it.
            foreach ($first ? [] : $plan['delete'] as $rel) {
                if (is_file("$dir/$rel")) {
                    unlink("$dir/$rel");
                }
                $out['deleted'][] = $rel;
                $pending[$rel] = null;
            }
            if ($out['written'] || $out['deleted']) {
                $draft->touch();
                Fs::writeJson($this->pendingFile(), $pending);
            }
            return $out;
        } finally {
            Fs::removeDir($scratch);
        }
    }

    /** After a publish: imported files that are now live become the baseline for the next deploy or import. */
    public static function confirm(App $app): void
    {
        $self = new self($app);
        $pending = $self->pending();
        if ($pending === []) {
            return;
        }
        $manifest = $self->manifest();
        foreach ($pending as $rel => $hash) {
            $live = $app->siteDir . "/$rel";
            if ($hash === null && !is_file($live)) {
                unset($manifest[$rel], $pending[$rel]);
            } elseif ($hash !== null && is_file($live) && sha1_file($live) === $hash) {
                $manifest[$rel] = $hash;
                unset($pending[$rel]);
            }
        }
        ksort($manifest);
        Fs::writeJson($app->varDir . '/deploy-design.json', $manifest);
        $pending ? Fs::writeJson($self->pendingFile(), $pending) : @unlink($self->pendingFile());
    }

    /** @return list<string> */
    private function check(string $rel, string $file): array
    {
        try {
            $rel = PathPolicy::normalise($rel);
            if (PathPolicy::isBinary($rel)) {
                return filesize($file) > 20 * 1024 * 1024 ? ['larger than 20 MB'] : [];
            }
            PathPolicy::checkWrite($rel);
        } catch (LouException $e) {
            return [$e->getMessage()];
        }
        return PathPolicy::checkContent($rel, (string) file_get_contents($file));
    }

    /** @return array<string, string> */
    private function manifest(): array
    {
        $file = $this->app->varDir . '/deploy-design.json';
        return is_file($file) ? Fs::readJson($file) : [];
    }

    /** @return array<string, ?string> */
    private function pending(): array
    {
        return is_file($this->pendingFile()) ? Fs::readJson($this->pendingFile()) : [];
    }

    private function pendingFile(): string
    {
        return $this->app->varDir . '/deploy-design.pending.json';
    }
}
