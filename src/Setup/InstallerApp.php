<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\Http\Request;
use Lou\Http\Response;
use Lou\LouException;
use Lou\Support\Html;

/**
 * The web installer, served by public/install.php from a release zip. Plain server-rendered form, no script.
 * POSTs need a token that matches a SameSite=Strict cookie (there is no session yet) and a same-origin request.
 */
final class InstallerApp
{
    public const CSP = "default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
    private const COOKIE = 'lou_install';

    private readonly Installer $installer;

    /**
     * @param string $docroot the folder install.php is in
     * @param string $script  install.php itself (deleted after installing into a document root it stays in)
     */
    public function __construct(
        private readonly string $root,
        private readonly string $docroot,
        private readonly string $script,
        ?Installer $installer = null,
    ) {
        $this->installer = $installer ?? new Installer($root);
    }

    public static function run(string $root, string $docroot, string $script): void
    {
        try {
            (new self($root, $docroot, $script))->handle(Request::fromGlobals())->send();
        } catch (\Throwable $e) {
            @file_put_contents($root . '/var/error.log', gmdate('c') . ' install.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Response::html(500, '<!doctype html><title>Error</title><p>The installer failed: ' . Html::escape($e->getMessage()) . '</p>')->send();
        }
    }

    public function handle(Request $req): Response
    {
        if (($req->query['asset'] ?? '') === 'admin.css') {
            return new Response(200, (string) file_get_contents($this->root . '/stubs/_lou/admin.css'), ['Content-Type' => 'text/css; charset=utf-8']);
        }
        if ($this->installer->installed()) {
            $this->removeScript();
            return $this->page(404, 'done', ['already' => true, 'warnings' => [], 'site' => '']);
        }
        if ($req->method === 'POST') {
            return $this->post($req);
        }
        return $this->form($req, null, []);
    }

    private function post(Request $req): Response
    {
        $cookie = $this->cookie($req);
        $source = $req->header('origin') ?: $req->header('referer');
        $host = strtolower(explode(':', $req->header('host'))[0]);
        if ($cookie === null || !hash_equals($cookie, $req->input('_csrf'))
            || ($source !== '' && strtolower((string) parse_url($source, PHP_URL_HOST)) !== $host)) {
            return $this->form($req, 'The form expired. It has been reloaded, please submit it again.', $req->post, 403);
        }
        $upload = $req->files['site_zip'] ?? null;
        $zip = null;
        if ($req->input('starter') === 'upload') {
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $tooBig = is_array($upload) && in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                return $this->form($req, $tooBig ? 'The zip is larger than this server accepts (' . ini_get('upload_max_filesize') . ').' : 'Choose the site .zip to upload.', $req->post, 422);
            }
            $tmp = (string) $upload['tmp_name'];
            if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
                return $this->form($req, 'The upload failed, try again.', $req->post, 422);
            }
            $zip = $tmp;
        }
        try {
            $r = $this->installer->install(array_map(static fn ($v) => is_string($v) ? $v : '', $req->post), $zip, $this->docroot);
        } catch (LouException $e) {
            return $this->form($req, $e->getMessage(), $req->post, 422);
        }
        $this->removeScript();
        return $this->page(200, 'done', ['already' => false, 'warnings' => $r['warnings'], 'site' => $r['site']]);
    }

    /** @param array<string, mixed> $old values to show again (never passwords or the key) */
    private function form(Request $req, ?string $error, array $old, int $status = 200): Response
    {
        $token = $this->cookie($req) ?? bin2hex(random_bytes(32));
        unset($old['admin_password'], $old['admin_password2'], $old['mail_pass'], $old['backup_password'], $old['_csrf']);
        $starters = $this->installer->starters();
        $res = $this->page($status, 'install', [
            'error'      => $error,
            'old'        => array_map(static fn ($v) => is_string($v) ? $v : '', $old),
            'csrf'       => $token,
            'checks'     => $this->installer->checks($this->docroot),
            'starters'   => $starters,
            'suggested'  => $req->origin(),
            'intoDocroot' => $this->installer->buildsIntoDocroot($this->docroot),
            'docroot'    => $this->docroot,
            'root'       => $this->root,
            'keyFile'    => $this->installer->keyFile(),
            'maxUpload'  => (string) ini_get('upload_max_filesize'),
        ]);
        try {
            $this->installer->key();   // make sure the key file exists before anyone is asked for it
        } catch (LouException) {
            // var/ is not writable: the checks on the page say so
        }
        return new Response($res->status, $res->body, $res->headers + [
            'Set-Cookie' => self::COOKIE . "=$token; Path=/; HttpOnly; SameSite=Strict" . ($req->https ? '; Secure' : ''),
        ]);
    }

    private function cookie(Request $req): ?string
    {
        foreach (explode(';', $req->header('cookie')) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === self::COOKIE && preg_match('/^[a-f0-9]{64}$/', $v)) {
                return $v;
            }
        }
        return null;
    }

    /** In a document root Lou doesn't own, install.php stays after the build, so it removes itself. */
    private function removeScript(): void
    {
        if (is_file($this->script) && basename($this->script) === 'install.php') {
            @unlink($this->script);
        }
    }

    /** @param array<string, mixed> $vars */
    private function page(int $status, string $view, array $vars): Response
    {
        $e = static fn (mixed $s): string => Html::escape((string) $s);
        $render = static function (string $__file, array $__vars) use ($e): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        };
        // after installing, install.php may be gone, but the build has put the stylesheet in place
        $css = $view === 'done' ? '/_lou/admin.css' : basename($this->script) . '?asset=admin.css';
        $body = $render(__DIR__ . "/views/$view.php", $vars);
        $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"robots\" content=\"noindex, nofollow\">\n<title>Install Lou</title>\n<link rel=\"stylesheet\" href=\"" . $e($css) . "\">\n</head>\n"
            . "<body class=\"guest\">\n<main class=\"install-wrap\">\n<p class=\"login-brand\">Lou</p>\n$body</main>\n</body>\n</html>\n";
        return new Response($status, $html, [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'X-Robots-Tag'            => 'noindex, nofollow',
        ]);
    }
}
