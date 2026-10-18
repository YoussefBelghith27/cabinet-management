<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'app'): string
    {
        if (!preg_match('#^[a-z0-9_/]+$#', $view)) {
            throw new \InvalidArgumentException('Nom de vue invalide.');
        }
        $content = self::capture(BASE_PATH . "/app/Views/{$view}.php", $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture(BASE_PATH . "/app/Views/layouts/{$layout}.php", ['content' => $content] + $data);
    }

    private static function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
