<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Web\Controller\ChannelsController;
use Magirc\Web\EncodingRouteParser;
use Magirc\Web\TemplateExtension;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Links built for channels and nicks lead back to the same name. */
final class LinksTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'plain channel' => ['channel', '#chat'];
        yield 'double hash' => ['channel', '##ChatLand'];
        yield 'only hashes' => ['channel', '##'];
        yield 'single hash' => ['channel', '#'];
        yield 'triple hash' => ['channel', '###'];
        yield 'reserved characters' => ['channel', '#a/b?c d%e'];
        yield 'non-ASCII' => ['channel', '#Café'];
        yield 'nick specials' => ['user', 'Foo\\Bar`[]^{}|'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function testRoundTrip(string $route, string $name): void
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->get('/channels/{channel:.+}', static function ($req, $res, array $args) {
            $res->getBody()->write(ChannelsController::channelName($args['channel']));
            return $res;
        })->setName('channel');
        $app->get('/users/{nick}', static function ($req, $res, array $args) {
            $res->getBody()->write($args['nick']);
            return $res;
        })->setName('user');

        $parser = new EncodingRouteParser($app->getRouteCollector()->getRouteParser());
        $url = $route === 'channel'
            ? $parser->urlFor('channel', ['channel' => TemplateExtension::channelSlug($name)])
            : $parser->urlFor('user', ['nick' => $name]);
        self::assertStringNotContainsString('#', $url);

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $url));
        self::assertSame(200, $response->getStatusCode(), $url);
        self::assertSame($name, (string) $response->getBody(), $url);
    }

    public function testPlainChannelKeepsShortUrl(): void
    {
        self::assertSame('chat', TemplateExtension::channelSlug('#chat'));
        self::assertSame('##ChatLand', TemplateExtension::channelSlug('##ChatLand'));
        self::assertSame('#', TemplateExtension::channelSlug('#'));
        self::assertSame(['channel' => 'a/b%3F'], EncodingRouteParser::encode(['channel' => 'a/b?']));
    }
}
