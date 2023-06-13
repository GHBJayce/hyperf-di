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
namespace Hyperf\Di\Annotation;

use Hyperf\Config\ProviderConfig;

use function Hyperf\Support\value;

class ScanConfig
{
    private static ?ScanConfig $instance = null;

    /**
     * @param array $paths the paths should be scanned everytime
     */
    public function __construct(
        private bool $cacheable,
        private string $configDir,
        private array $paths = [],
        private array $dependencies = [],
        private array $ignoreAnnotations = [],
        private array $globalImports = [],
        private array $collectors = [],
        private array $classMap = []
    ) {
    }

    public function isCacheable(): bool
    {
        return $this->cacheable;
    }

    public function getConfigDir(): string
    {
        return $this->configDir;
    }

    /**
     * @return array 返回数据参考：[
     *  '/project/vendor/hyperf/di/src',
     *  '/project/vendor/hyperf/model-listener/src',
     *  '/project/vendor/hyperf/devtool/src',
     *  '/project/app',
     * ]
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * 注解收集器
     * @return array 返回数据参考：[
     *  'Hyperf\Cache\CacheListenerCollector',
     *  'Hyperf\Di\Annotation\AnnotationCollector',
     * ]
     */
    public function getCollectors(): array
    {
        return $this->collectors;
    }

    public function getIgnoreAnnotations(): array
    {
        return $this->ignoreAnnotations;
    }

    public function getGlobalImports(): array
    {
        return $this->globalImports;
    }

    /**
     * @return array 返回数据参考：[
     *  'Hyperf\Amqp\Producer' => 'Hyperf\Amqp\Producer',
     *  'Psr\SimpleCache\CacheInterface' => 'Hyperf\Cache\Cache',
     *  'db.connector.mysql' => 'Hyperf\Database\Connectors\MySqlConnector',
     *  'Hyperf\Contract\ApplicationInterface' => 'Hyperf\Framework\ApplicationFactory',
     * ]
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * 类替换映射
     * https://hyperf.wiki/3.0/#/zh-cn/annotation?id=classmap-%e5%8a%9f%e8%83%bd
     * @return array
     */
    public function getClassMap(): array
    {
        return $this->classMap;
    }

    /**
     * 收集配置并实例化自己
     * @param string $configDir
     * @return self
     */
    public static function instance(string $configDir): self
    {
        if (self::$instance) {
            return self::$instance;
        }

        $configDir = rtrim($configDir, '/');

        [$config, $serverDependencies, $cacheable] = static::initConfigByFile($configDir);

        return self::$instance = new self(
            $cacheable,
            $configDir,
            $config['paths'] ?? [], // annotations.scan里的属性，数据来源（优先级从大到小）：config/config.php + config/autoload/annotations.php + composer.lock
            $serverDependencies ?? [], // 数据来源（优先级从大到小）：config/autoload/dependencies.php + composer.lock
            $config['ignore_annotations'] ?? [], // 同paths
            $config['global_imports'] ?? [], // 同paths
            $config['collectors'] ?? [], // 同paths
            $config['class_map'] ?? [] // 同paths
        );
    }

    private static function initConfigByFile(string $configDir): array
    {
        $config = [];
        $configFromProviders = [];
        $cacheable = false;
        if (class_exists(ProviderConfig::class)) {
            // 从composer.lock中获取hyperf的配置数据，并处理成指定格式
            $configFromProviders = ProviderConfig::load();
        }

        // 合并用户config/autoload/dependencies.php配置 优先级config/autoload/dependencies.php > composer.lock中的配置
        $serverDependencies = $configFromProviders['dependencies'] ?? [];
        if (file_exists($configDir . '/autoload/dependencies.php')) {
            $definitions = include $configDir . '/autoload/dependencies.php';
            $serverDependencies = array_replace($serverDependencies, $definitions ?? []);
        }

        // 摘取composer.lock中annotations下scan里的的配置
        $config = static::allocateConfigValue($configFromProviders['annotations'] ?? [], $config);

        // 合并用户config/autoload/annotations.php中的scan配置 跟dependencies一样
        // Load the config/autoload/annotations.php and merge the config
        if (file_exists($configDir . '/autoload/annotations.php')) {
            $annotations = include $configDir . '/autoload/annotations.php';
            $config = static::allocateConfigValue($annotations, $config);
        }

        // Load the config/config.php and merge the config
        if (file_exists($configDir . '/config.php')) {
            $configContent = include $configDir . '/config.php';
            $appEnv = $configContent['app_env'] ?? 'dev';
            // scan_cacheable没配置的情况下线上环境为开启
            $cacheable = value($configContent['scan_cacheable'] ?? $appEnv === 'prod');
            // 合并config/config.php中的annotations，优先级情况：config.php > autoload/annotations.php > composer.lock中的配置
            if (isset($configContent['annotations'])) {
                $config = static::allocateConfigValue($configContent['annotations'], $config);
            }
        }

        return [$config, $serverDependencies, $cacheable];
    }

    /**
     * 合并两个参数中scan里的属性并返回
     * @param array $content
     * 参考文件：\Hyperf\Di\ConfigProvider::__invoke、\Hyperf\Constants\ConfigProvider::__invoke、\Hyperf\ModelListener\ConfigProvider::__invoke
     * 参考格式：[
     *  'scan' => [
     *      'paths' => [
     *          __DIR__,
     *      ],
     *      'collectors' => [
     *          AnnotationCollector::class,
     *          AspectCollector::class,
     *      ],
     *  ],
     *  'ignore_annotations' => [
     *      'mixin',
     *  ],
     * ]
     * @param array $config
     * @return array
     */
    private static function allocateConfigValue(array $content, array $config): array
    {
        if (! isset($content['scan'])) {
            return [];
        }
        foreach ($content['scan'] as $key => $value) {
            if (! isset($config[$key])) {
                $config[$key] = [];
            }
            if (! is_array($value)) {
                $value = [$value];
            }
            $config[$key] = array_merge($config[$key], $value);
        }
        return $config;
    }
}
