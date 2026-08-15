<?php

namespace GlpiPlugin\Gdmsintegration;
class Twig {
    private static ?\Twig\Environment $twig = null;

    public static function get(): \Twig\Environment {
        if (self::$twig !== null) return self::$twig;
        $loader = new \Twig\Loader\FilesystemLoader(
            dirname(__DIR__) . '/templates'
        );
        $env = new \Twig\Environment($loader, [
            'autoescape'       => 'html',
            'strict_variables' => false,
        ]);
        $env->addFunction(new \Twig\TwigFunction('__', '__'));
        $env->addFunction(new \Twig\TwigFunction('sprintf', 'sprintf'));
        self::$twig = $env;
        return $env;
    }
}
