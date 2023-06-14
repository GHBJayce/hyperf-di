<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Di;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter;
use Dotenv\Repository\RepositoryBuilder;
use Hyperf\Di\Annotation\ScanConfig;
use Hyperf\Di\Annotation\Scanner;
use Hyperf\Di\LazyLoader\LazyLoader;
use Hyperf\Di\ScanHandler\PcntlScanHandler;
use Hyperf\Di\ScanHandler\ScanHandlerInterface;
use Hyperf\Support\Composer;

class ClassLoader
{
    public static function init(?string $proxyFileDirPath = null, ?string $configDir = null, ?ScanHandlerInterface $handler = null): void
    {
        if (! $proxyFileDirPath) {
            // This dir is the default proxy file dir path of Hyperf
            $proxyFileDirPath = BASE_PATH . '/runtime/container/proxy/';
        }

        if (! $configDir) {
            // This dir is the default proxy file dir path of Hyperf
            $configDir = BASE_PATH . '/config/';
        }

        if (! $handler) {
            $handler = new PcntlScanHandler();
        }

        $composerLoader = Composer::getLoader();

        if (file_exists(BASE_PATH . '/.env')) {
            static::loadDotenv();
        }

        // 主要从config/config.php、config/autoload/xxx.php、composer.lock中收集annotation.scan和dependencies的配置
        // Scan by ScanConfig to generate the reflection class map
        $config = ScanConfig::instance($configDir);
        // $config->getClassMap()是什么内容？
        $composerLoader->addClassMap($config->getClassMap());

        /*
         * classMap是一个数组，存放命名空间和对应文件存放位置的键值映射，例如：
         * $classMap = [
         *  'App\Controller\IndexController' => '/project_path/runtime/container/proxy/App_Controller_IndexController.proxy.php',
         *  'App\Model\Model' => '/project_path/vendor/composer/../../app/Model/Model.php',
         *  'GuzzleHttp\Pool' => '/project_path/vendor/composer/../guzzlehttp/guzzle/src/Pool.php',
         * ]
         */
        $scanner = new Scanner($config, $handler);
        // 覆盖成代理类的文件路径
        $composerLoader->addClassMap(
            // 扫描composer中的classMap，找出要代理的类文件，并处理成：类名 => 代理文件存放位置的格式
            $scanner->scan($composerLoader->getClassMap(), $proxyFileDirPath)
        );

        // Initialize Lazy Loader. This will prepend LazyLoader to the top of autoload queue.
        LazyLoader::bootstrap($configDir);
    }

    protected static function loadDotenv(): void
    {
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(Adapter\PutenvAdapter::class)
            ->immutable()
            ->make();

        Dotenv::create($repository, [BASE_PATH])->load();
    }
}
