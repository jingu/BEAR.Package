<?php
/**
 * This file is part of the BEAR.Package package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\Package;

use BEAR\AppMeta\AbstractAppMeta;
use BEAR\AppMeta\AppMeta;
use BEAR\Package\Provide\Error\NullPage;
use BEAR\Resource\Exception\ParameterException;
use BEAR\Resource\NamedParameterInterface;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;
use Ray\Di\AbstractModule;
use Ray\Di\Bind;
use Ray\Di\InjectorInterface;

final class Compiler
{
    private $classes = [];

    private $files = [];

    /**
     * Compile application
     *
     * @param string $appName application name "MyVendor|MyProject"
     * @param string $context application context "prod-app"
     * @param string $appDir  application path
     */
    public function __invoke(string $appName, string $context, string $appDir) : string
    {
        $loader = $this->compileLoader($appName, $context, $appDir);
        $log = $this->compileDiScripts($appName, $context, $appDir);

        return sprintf("%s\nautload.php: %s", $log, $loader);
    }

    public function compileDiScripts(string $appName, string $context, string $appDir) : string
    {
        $appMeta = new AppMeta($appName, $context, $appDir);
        (new Unlink)->force($appMeta->tmpDir);
        $injector = new AppInjector($appName, $context);
        $cache = $injector->getInstance(Cache::class);
        $reader = $injector->getInstance(AnnotationReader::class);
        /* @var $reader \Doctrine\Common\Annotations\Reader */
        $namedParams = $injector->getInstance(NamedParameterInterface::class);
        /* @var $namedParams NamedParameterInterface */

        // create DI factory class and AOP compiled class for all resources and save $app cache.
        (new Bootstrap)->newApp($appMeta, $context, $cache);

        // check resource injection and create annotation cache
        foreach ($appMeta->getResourceListGenerator() as list($className)) {
            $this->scanClass($injector, $reader, $namedParams, $className);
        }
        $logFile = $appMeta->logDir . '/compile.log';
        $this->saveCompileLog($appMeta, $context, $logFile);

        return $logFile;
    }

    private function compileLoader(string $appName, string $context, string $appDir) : string
    {
        $loader = $appDir . '/vendor/autoload.php';
        if (! file_exists($loader)) {
            return '';
        }
        $loader = require $loader;
        spl_autoload_register(
            function ($class) use ($loader) {
                $loader->loadClass($class);
                if ($class !== NullPage::class) {
                    $this->classes[] = $class;
                }
            },
            false,
            true
        );

        $this->invokeTypicalReuqest($appName, $context);
        $fies = '<?php' . PHP_EOL;
        foreach ($this->classes as $class) {
            $fies .= sprintf(
                "require %s';\n",
                $this->getRelaticePath($appDir, (new \ReflectionClass($class))->getFileName())
            );
        }
        $fies .= "require __DIR__ . '/vendor/autoload.php';" . PHP_EOL . PHP_EOL;
        $laoder = $appDir . '/autoload.php';
        file_put_contents($laoder, $fies);

        return $laoder;
    }

    private function getRelaticePath(string $rootDir, string $file)
    {
        if (strpos($file, $rootDir) !== false) {
            return str_replace("{$rootDir}", "__DIR__ . '", $file);
        }

        return "'" . $file;
    }

    private function invokeTypicalReuqest(string $appName, string $context)
    {
        $app = (new Bootstrap)->getApp($appName, $context);
        $ro = new NullPage;
        $ro->uri = new Uri('app://self/');
        $app->resource->get->object($ro)();
    }

    private function scanClass(InjectorInterface $injector, Reader $reader, NamedParameterInterface $namedParams, string $className)
    {
        $instance = $injector->getInstance($className);
        $class = new \ReflectionClass($className);
        $reader->getClassAnnotations($class);
        $methods = $class->getMethods();
        foreach ($methods as $method) {
            $methodName = $method->getName();
            if ($this->isMagicMethod($methodName)) {
                continue;
            }
            $this->saveNamedParam($namedParams, $instance, $methodName);
            // method annotation
            $reader->getMethodAnnotations($method);
        }
    }

    private function isMagicMethod($method) : bool
    {
        return \in_array($method, ['__sleep', '__wakeup', 'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', 'count', 'ksort', 'asort', 'jsonSerialize'], true);
    }

    private function saveNamedParam(NamedParameterInterface $namedParameter, $instance, string $method)
    {
        // named parameter
        if (! \in_array($method, ['onGet', 'onPost', 'onPut', 'onPatch', 'onDelete', 'onHead'], true)) {
            return;
        }
        try {
            $namedParameter->getParameters([$instance, $method], []);
        } catch (ParameterException $e) {
            return;
        }
    }

    private function saveCompileLog(AbstractAppMeta $appMeta, string $context, string $logFile)
    {
        $module = (new Module)($appMeta, $context);
        /** @var AbstractModule $module */
        $container = $module->getContainer();
        foreach ($appMeta->getResourceListGenerator() as list($class)) {
            new Bind($container, $class);
        }
        file_put_contents($logFile, (string) $module);
    }
}
