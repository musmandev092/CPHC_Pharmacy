<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Tiny template helper.  Renders a PHP file from src/templates/ or
 * src/pages/ with $vars extracted as locals.  No engine, no syntax tax.
 *
 * Conventions inside templates:
 *   - All dynamic output MUST use `<?= e(...) ?>`.  The CI lint enforces this.
 *   - Inline <script> and <style> blocks MUST carry nonce="<?= Csp::nonce() ?>".
 *   - Use renderLayout($title, $body, $vars=[]) for full pages so they get
 *     the standard nav + flash + CSRF setup.
 */
final class View
{
    /** Render a partial inside frontend/templates/. */
    public static function partial(string $name, array $vars = []): string
    {
        $path = __DIR__ . '/../frontend/templates/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Missing partial: ' . $path);
        }
        return self::capture($path, $vars);
    }

    /** Render a full page with the standard layout. */
    public static function page(string $title, string $bodyTemplate, array $vars = []): void
    {
        $body = self::partial($bodyTemplate, $vars);
        echo self::partial('layout', [
            'title' => $title,
            'body'  => $body,
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
            'nonce'         => Csp::nonce(),
        ]);
    }

    private static function capture(string $file, array $vars): string
    {
        // Sandbox: variables are extracted, but the file itself runs in this scope.
        return (static function (string $__file, array $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        })($file, $vars);
    }
}
