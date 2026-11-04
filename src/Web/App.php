<?php

declare(strict_types=1);

namespace Magirc\Web;

use DI\Container;
use Magirc\Services;
use Magirc\Version;
use Magirc\Web\Controller\ActivityController;
use Magirc\Web\Controller\ChannelsController;
use Magirc\Web\Controller\HomeController;
use Magirc\Web\Controller\OperatorsController;
use Magirc\Web\Controller\ServersController;
use Magirc\Web\Controller\UsersController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\Extra\Intl\IntlExtension;

/**
 * Builds the Slim application: container, Twig, routes and error pages.
 */
final class App
{
    public static function create(Services $services): SlimApp
    {
        $config = $services->config;
        $container = new Container();
        $container->set(Services::class, $services);
        $locales = new Locales($services->rootDir . '/locale', $config);
        $container->set(Locales::class, $locales);
        // The request locale is decided by LocaleMiddleware before Twig is built.
        $container->set('locale', $locales->default());
        // Translator and Twig are built per request for the resolved locale
        // (see the locale middleware); Twig::class holds the current one.
        $container->set(Translator::class, static fn (Container $c) => Translator::load($services->rootDir . '/locale', (string) $c->get('locale')));
        $container->set('twig.factory', static fn (Container $c): \Closure => static fn (): Twig => self::twig($services, $c->get(Translator::class), $locales));
        $container->set(Twig::class, static fn (Container $c): Twig => ($c->get('twig.factory'))());

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $basePath = rtrim($config->string('app.base_path'), '/');
        if ($basePath !== '') {
            $app->setBasePath($basePath);
        }

        $app->addRoutingMiddleware();
        // Decide the request locale first, then build Twig (and the translator)
        // for it; TwigMiddleware::createFromContainer would build Twig too early.
        $app->add(function (ServerRequestInterface $request, $handler) use ($app, $container, $locales, $services) {
            $locale = $locales->resolve($request);
            $container->set('locale', $locale);
            \Locale::setDefault($locale);
            $translator = Translator::load($services->rootDir . '/locale', $locale);
            $container->set(Translator::class, $translator);
            $services->geoip()->setLocale($locale, static fn (string $name): string => $translator->t($name));
            $twig = ($container->get('twig.factory'))();
            $container->set(Twig::class, $twig);
            return (new TwigMiddleware($twig, new EncodingRouteParser($app->getRouteCollector()->getRouteParser()), $app->getBasePath()))->process($request, $handler);
        });
        $errorMiddleware = $app->addErrorMiddleware($config->bool('app.debug'), true, true);
        // Twig is resolved lazily so the request locale is known by the time it is built.
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler($app, static fn (): Twig => $container->get(Twig::class)));

        self::routes($app);
        return $app;
    }

    private static function twig(Services $services, Translator $translator, Locales $locales): Twig
    {
        $config = $services->config;
        $theme = $config->string('display.theme', 'default');
        $twig = Twig::create($services->rootDir . '/templates/' . $theme, [
            'cache' => $config->bool('app.debug') ? false : $services->cacheDir() . '/twig',
            // Recompile a template when its file changes, so a `git pull`
            // takes effect without clearing tmp/twig by hand.
            'auto_reload' => true,
            'debug' => $config->bool('app.debug'),
            'strict_variables' => false,
        ]);
        $env = $twig->getEnvironment();
        $env->addExtension(new IntlExtension());
        $env->addExtension(new TemplateExtension($services, $translator));
        $env->addGlobal('cfg', [
            'network' => $config->get('network'),
            'display' => $config->get('display'),
            'chanstats' => $config->chanstatsEnabled(),
            'geoip' => $services->geoip()->enabled(),
            'version' => Version::STRING,
            'base_path' => rtrim($config->string('app.base_path'), '/'),
        ]);
        $env->addGlobal('locale', $translator->locale());
        $env->addGlobal('locales', $locales->available());
        return $twig;
    }

    private static function routes(SlimApp $app): void
    {
        $app->get('/', [HomeController::class, 'home'])->setName('home');
        $app->get('/network', [HomeController::class, 'network'])->setName('network');
        $app->get('/network/countries', [HomeController::class, 'countries'])->setName('network.countries');
        $app->get('/network/countries/{code:[a-z]{2}|unknown}', [HomeController::class, 'country'])->setName('network.country');

        $app->get('/servers', [ServersController::class, 'index'])->setName('servers');
        $app->get('/servers/{server}', [ServersController::class, 'show'])->setName('server');

        $app->get('/channels', [ChannelsController::class, 'index'])->setName('channels');
        $app->get('/channels/{channel:.+}', [ChannelsController::class, 'show'])->setName('channel');

        $app->get('/users', [UsersController::class, 'index'])->setName('users');
        $app->get('/users/{nick}', [UsersController::class, 'show'])->setName('user');
        $app->get('/operators', [OperatorsController::class, 'index'])->setName('operators');

        $app->get('/activity', [ActivityController::class, 'index'])->setName('activity');
        $app->get('/activity/channels/{channel:.+}', [ActivityController::class, 'channel'])->setName('activity.channel');
        $app->get('/activity/users/{account}', [ActivityController::class, 'user'])->setName('activity.user');

        $app->get('/api/status', [HomeController::class, 'apiStatus'])->setName('api.status');
        $app->get('/api/history', [HomeController::class, 'apiHistory'])->setName('api.history');
        $app->get('/api/channels', [ChannelsController::class, 'api'])->setName('api.channels');
        $app->get('/api/users', [UsersController::class, 'api'])->setName('api.users');
        $app->get('/api/servers', [ServersController::class, 'api'])->setName('api.servers');
        $app->get('/api/countries', [HomeController::class, 'apiCountries'])->setName('api.countries');

        $app->get('/content/{page}', [HomeController::class, 'content'])->setName('content');
        $app->get('/lang/{code}', [HomeController::class, 'language'])->setName('language');
    }

    public static function json(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
