<?php

declare(strict_types=1);

namespace BEAR\Package;

use BEAR\AppMeta\AbstractAppMeta;
use BEAR\AppMeta\Meta;
use BEAR\Sunday\Extension\Application\AbstractApp;
use BEAR\Sunday\Extension\Application\AppInterface;
use Doctrine\Common\Cache\Cache;

use function is_string;
use malkusch\lock\mutex\FlockMutex;

final class Bootstrap
{
    /**
     * Return application instance
     *
     * Use newApp() instead for your own AppMeta and Cache.
     *
     * @param string $name           application name    'koriym\blog' (vendor\package)
     * @param string $contexts       application context 'prod-html-app'
     * @param string $appDir         application path
     * @param string $cacheNamespace cache key changed every time you deploy
     */
    public function getApp(string $name, string $contexts, string $appDir = '', string $cacheNamespace = null) : AbstractApp
    {
        return $this->newApp(new Meta($name, $contexts, $appDir), $contexts, null, $cacheNamespace);
    }

    public function newApp(AbstractAppMeta $appMeta, string $contexts, Cache $cache = null, string $cacheNamespace = null) : AbstractApp
    {
        $cacheNamespace = is_string($cacheNamespace) ? $cacheNamespace : (string) filemtime($appMeta->appDir . '/src');
        $injector = new AppInjector($appMeta->name, $contexts, $appMeta, $cacheNamespace);
        $cache = $cache instanceof Cache ? $cache : $injector->getCachedInstance(Cache::class); // array cache in non-production
        assert($cache instanceof Cache);
        $appId = $appMeta->name . $contexts . $cacheNamespace;
        $app = $cache->fetch($appId);
        if ($app instanceof AbstractApp) {
            return $app;
        }
        $mutex = new FlockMutex(fopen(__FILE__, 'rb'));
        return $mutex->synchronized(function () use ($injector, $cache, $appId) : AbstractApp {
            $app = $cache->fetch($appId);
            if ($app instanceof AbstractApp) {
                return $app;
            }
            $injector->disableCache();
            $app = $injector->getCachedInstance(AppInterface::class);
            $cache->save($appId, $app);

            return $app;
        });
    }
}
