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
use Hyperf\Di\Aop\ProxyManager;
use Hyperf\Di\Exception\DirectoryNotExistException;
use Hyperf\Di\MetadataCollector;
use Hyperf\Di\ReflectionManager;
use Hyperf\Di\ScanHandler\ScanHandlerInterface;
use Hyperf\Support\Composer;
use Hyperf\Support\Filesystem\Filesystem;
use ReflectionClass;

class Scanner
{
    protected Filesystem $filesystem;

    protected string $path = BASE_PATH . '/runtime/container/scan.cache';

    public function __construct(protected ScanConfig $scanConfig, protected ScanHandlerInterface $handler)
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * 收集类文件中的注解（类注解、方法注解、属性注解）交由对应的注解收集器处理
     * 相关文档：https://www.php.net/manual/zh/language.attributes.php
     * @param AnnotationReader $reader
     * @param ReflectionClass $reflection
     * @return void
     */
    public function collect(AnnotationReader $reader, ReflectionClass $reflection)
    {
        $className = $reflection->getName();
        if ($path = $this->scanConfig->getClassMap()[$className] ?? null) {
            if ($reflection->getFileName() !== $path) {
                // When the original class is dynamically replaced, the original class should not be collected.
                return;
            }
        }
        // 通过PHP8.0特性对反射类中的注解，对该注解对应的收集器类进行实例化，例如：\Hyperf\Di\Annotation\Aspect
        // Parse class annotations
        $classAnnotations = $reader->getClassAnnotations($reflection);
        if (! empty($classAnnotations)) {
            // 注解收集器类对$className这个类文件做类数据搜集处理
            foreach ($classAnnotations as $classAnnotation) {
                if ($classAnnotation instanceof AnnotationInterface) {
                    $classAnnotation->collectClass($className);
                }
            }
        }
        // Parse properties annotations
        $properties = $reflection->getProperties();
        foreach ($properties as $property) {
            // 对反射类中的所有属性（不管什么访问类型）的注解收集器类进行实例化
            $propertyAnnotations = $reader->getPropertyAnnotations($property);
            if (! empty($propertyAnnotations)) {
                // 注解收集器类对$className这个类文件做属性数据搜集处理
                foreach ($propertyAnnotations as $propertyAnnotation) {
                    if ($propertyAnnotation instanceof AnnotationInterface) {
                        $propertyAnnotation->collectProperty($className, $property->getName());
                    }
                }
            }
        }
        // Parse methods annotations
        $methods = $reflection->getMethods();
        foreach ($methods as $method) {
            // 对反射类中的所有方法的注解收集器类进行实例化
            $methodAnnotations = $reader->getMethodAnnotations($method);
            if (! empty($methodAnnotations)) {
                // 注解收集器类对$className这个类文件做方法数据搜集处理
                foreach ($methodAnnotations as $methodAnnotation) {
                    if ($methodAnnotation instanceof AnnotationInterface) {
                        $methodAnnotation->collectMethod($className, $method->getName());
                    }
                }
            }
        }

        unset($reflection, $classAnnotations, $properties, $methods);
    }

    /**
     * @param array $classMap composer的ClassMap
     * @param string $proxyDir
     * @return array 类名 => 代理类文件存放位置
     * @throws DirectoryNotExistException
     */
    public function scan(array $classMap = [], string $proxyDir = ''): array
    {
        // 需要扫描注解文件的目录
        $paths = $this->scanConfig->getPaths();
        // 扫描注解文件时的注解数据收集器
        $collectors = $this->scanConfig->getCollectors();
        if (! $paths) {
            return [];
        }

        // 文件存在且修改过且启用代理缓存，直接解析文件内的数据返回
        $lastCacheModified = file_exists($this->path) ? $this->filesystem->lastModified($this->path) : 0;
        if ($lastCacheModified > 0 && $this->scanConfig->isCacheable()) {
            return $this->deserializeCachedScanData($collectors);
        }

        // 当handler为\Hyperf\Di\ScanHandler\PcntlScanHandler
        $scanned = $this->handler->scan();
        if ($scanned->isScanned()) {
            // 父进程进到该逻辑
            return $this->deserializeCachedScanData($collectors);
        }

        // 以下则是子进程要做的事情
        $this->deserializeCachedScanData($collectors);

        $annotationReader = new AnnotationReader($this->scanConfig->getIgnoreAnnotations());

        // 检查路径是否存在
        $paths = $this->normalizeDir($paths);

        // 获取路径下的所有类（class、interface、abstract）文件（trait不算），处理成反射类数组，key是类的命名空间，value是它的反射类
        $classes = ReflectionManager::getAllClasses($paths);

        // 从classes.cache缓存文件中清理掉本次已经去掉的类，并将本次扫描结果存进classes.cache文件中
        $this->clearRemovedClasses($collectors, $classes);

        // 类命名空间 => 类文件的存储位置（/xxx/xxx/xxx.php）
        $reflectionClassMap = [];
        foreach ($classes as $className => $reflectionClass) {
            $reflectionClassMap[$className] = $reflectionClass->getFileName();
            if ($this->filesystem->lastModified($reflectionClass->getFileName()) >= $lastCacheModified) {
                // 从注解收集器中剔除掉扫出来的类
                /** @var MetadataCollector $collector */
                foreach ($collectors as $collector) {
                    $collector::clear($className);
                }

                // 收集类文件中的注解，并交由对应的注解收集器进行处理
                $this->collect($annotationReader, $reflectionClass);
            }
        }

        // 从config/autoload/aspects.php > config/config.php > composer.lock三个地方读取切面配置并加载到切面收集器中，会生成runtime/container/aspects.cache文件
        $this->loadAspects($lastCacheModified);

        $data = [];
        /** @var MetadataCollector|string $collector */
        foreach ($collectors as $collector) {
            $data[$collector] = $collector::serialize();
        }

        // Get the class map of Composer loader
        $classMap = array_merge($reflectionClassMap, $classMap);
        // 从composer中的classMap中找出需要代理的类，并生成代理类文件和runtime/container/scan.cache缓存文件
        $proxyManager = new ProxyManager($classMap, $proxyDir);
        $proxies = $proxyManager->getProxies();
        $aspectClasses = $proxyManager->getAspectClasses();

        $this->putCache($this->path, serialize([$data, $proxies, $aspectClasses]));
        exit;
    }

    /**
     * Normalizes given directory names by removing directory not exist.
     * @throws DirectoryNotExistException
     */
    public function normalizeDir(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $result[] = $path;
            }
        }

        if ($paths && ! $result) {
            throw new DirectoryNotExistException('The scanned directory does not exist');
        }

        return $result;
    }

    protected function deserializeCachedScanData(array $collectors): array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        // $data是多个收集器（一维数组）、$proxies是类名（含命名空间） => 代理文件存放位置
        [$data, $proxies] = unserialize(file_get_contents($this->path));
        foreach ($data as $collector => $deserialized) {
            /** @var MetadataCollector $collector */
            if (in_array($collector, $collectors)) {
                $collector::deserialize($deserialized);
            }
        }

        return $proxies;
    }

    /**
     * 从classes.cache缓存文件中清理不要的类
     * @param ReflectionClass[] $reflections annotations.scan.path目录下的所有类文件的反射类数组
     */
    protected function clearRemovedClasses(array $collectors, array $reflections): void
    {
        $path = BASE_PATH . '/runtime/container/classes.cache';
        $classes = array_keys($reflections);

        $data = [];
        if ($this->filesystem->exists($path)) {
            $data = unserialize($this->filesystem->get($path));
        }

        // 将annotations.scan.path目录下的所有类（类名）写进上面的classes.cache文件中
        $this->putCache($path, serialize($classes));

        // 找出要删除的类（本次扫出的类与classes.cache中的所有类进行对比）
        $removed = array_diff($data, $classes);

        // 从注解搜集器中剔除这些类
        foreach ($removed as $class) {
            /** @var MetadataCollector $collector */
            foreach ($collectors as $collector) {
                $collector::clear($class);
            }
        }
    }

    protected function putCache(string $path, $data)
    {
        if (! $this->filesystem->isDirectory($dir = dirname($path))) {
            $this->filesystem->makeDirectory($dir, 0755, true);
        }

        $this->filesystem->put($path, $data);
    }

    /**
     * Load aspects to AspectCollector by configuration files and ConfigProvider.
     */
    protected function loadAspects(int $lastCacheModified): void
    {
        $configDir = $this->scanConfig->getConfigDir();
        if (! $configDir) {
            return;
        }

        // 读取切面配置
        $aspectsPath = $configDir . '/autoload/aspects.php';
        $basePath = $configDir . '/config.php';
        $aspects = file_exists($aspectsPath) ? include $aspectsPath : [];
        $baseConfig = file_exists($basePath) ? include $basePath : [];
        $providerConfig = [];
        if (class_exists(ProviderConfig::class)) {
            $providerConfig = ProviderConfig::load();
        }
        // 切面配置默认值处理
        if (! isset($aspects) || ! is_array($aspects)) {
            $aspects = [];
        }
        if (! isset($baseConfig['aspects']) || ! is_array($baseConfig['aspects'])) {
            $baseConfig['aspects'] = [];
        }
        if (! isset($providerConfig['aspects']) || ! is_array($providerConfig['aspects'])) {
            $providerConfig['aspects'] = [];
        }
        // 多个切面配置合并，优先级：config/autoload/aspects.php > config/config.php > composer.lock
        $aspects = array_merge($providerConfig['aspects'], $baseConfig['aspects'], $aspects);

        // 对比/runtime/container/aspects.cache文件得出要删除的和新增的切面
        [$removed, $changed] = $this->getChangedAspects($aspects, $lastCacheModified);
        // When the aspect removed from config, it should be removed from AspectCollector.
        foreach ($removed as $aspect) {
            AspectCollector::clear($aspect);
        }

        foreach ($aspects as $key => $value) {
            if (is_numeric($key)) {
                $aspect = $value;
                $priority = null;
            } else {
                $aspect = $key;
                $priority = (int) $value;
            }

            // 跳过不是新增的切面
            if (! in_array($aspect, $changed)) {
                continue;
            }

            // 读取切面定义文件中的切面配置数据，另一个被调用的地方：\Hyperf\Di\Annotation\Aspect::collect
            [$instanceClasses, $instanceAnnotations, $instancePriority] = AspectLoader::load($aspect);

            $classes = $instanceClasses ?: [];
            // Annotations
            $annotations = $instanceAnnotations ?: [];
            // Priority
            $priority = $priority ?: ($instancePriority ?? null);
            // Save the metadata to AspectCollector
            AspectCollector::setAround($aspect, $classes, $annotations, $priority);
        }
    }

    /**
     * 对比aspects.cache得出要移除的和新增的切面集合
     * @param array $aspects
     * @param int $lastCacheModified
     * @return array
     * @throws \Hyperf\Support\Filesystem\FileNotFoundException
     */
    protected function getChangedAspects(array $aspects, int $lastCacheModified): array
    {
        $path = BASE_PATH . '/runtime/container/aspects.cache';
        $classes = [];
        foreach ($aspects as $key => $value) {
            if (is_numeric($key)) {
                $classes[] = $value;
            } else {
                $classes[] = $key;
            }
        }

        $data = [];
        if ($this->filesystem->exists($path)) {
            $data = unserialize($this->filesystem->get($path));
        }

        $this->putCache($path, serialize($classes));

        $diff = array_diff($data, $classes);
        $changed = array_diff($classes, $data);
        $removed = [];
        foreach ($diff as $item) {
            $annotation = AnnotationCollector::getClassAnnotation($item, Aspect::class);
            if (is_null($annotation)) {
                $removed[] = $item;
            }
        }
        foreach ($classes as $class) {
            $file = Composer::getLoader()->findFile($class);
            if ($file === false) {
                echo sprintf('Skip class %s, because it does not exist in composer class loader.', $class) . PHP_EOL;
                continue;
            }
            if ($lastCacheModified <= $this->filesystem->lastModified($file)) {
                $changed[] = $class;
            }
        }

        return [
            array_values(array_unique($removed)),
            array_values(array_unique($changed)),
        ];
    }
}
