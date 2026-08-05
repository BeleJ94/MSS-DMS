<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function partial(string $view, array $data = []): string
    {
        return self::render($view, $data, '');
    }

    public static function render(string $view, array $data = [], string $layout = 'layouts/main'): string
    {
        $viewFile = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException('Vue introuvable : ' . $view);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        if ($layout === '') {
            return $content;
        }

        $layoutFile = BASE_PATH . '/app/Views/' . $layout . '.php';
        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
