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
namespace Hyperf\Di\Aop;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Annotation\AspectCollector;
use Hyperf\Support\Filesystem\Filesystem;

class ProxyManager
{
    /**
     * The classes which be rewritten by proxy.
     */
    protected array $proxies = [];

    protected Filesystem $filesystem;

    /**
     * @param array $classMap the map to collect the classes with paths
     * @param string $proxyDir the directory which the proxy file places in
     */
    public function __construct(
        protected array $classMap = [],
        protected string $proxyDir = ''
    ) {
        $this->filesystem = new Filesystem();
        $this->proxies = $this->generateProxyFiles($this->initProxiesByReflectionClassMap(
            $this->classMap
        ));
    }

    public function getProxies(): array
    {
        return $this->proxies;
    }

    public function getProxyDir(): string
    {
        return $this->proxyDir;
    }

    /**
     * @return array [切面定义类][切入的类/规则] => 代理文件存放位置
     */
    public function getAspectClasses(): array
    {
        $aspectClasses = [];
        $classesAspects = AspectCollector::get('classes', []);
        foreach ($classesAspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                if (isset($this->proxies[$rule])) {
                    $aspectClasses[$aspect][$rule] = $this->proxies[$rule];
                }
            }
        }
        return $aspectClasses;
    }

    /**
     * 生成代理文件
     * @param array $proxies
     * @return array 类名（含命名空间） => 代理文件存放位置
     */
    protected function generateProxyFiles(array $proxies = []): array
    {
        $proxyFiles = [];
        if (! $proxies) {
            return $proxyFiles;
        }
        if (! file_exists($this->getProxyDir())) {
            mkdir($this->getProxyDir(), 0755, true);
        }
        // WARNING: Ast class SHOULD NOT use static instance, because it will read  the code from file, then would be caused coroutine switch.
        $ast = new Ast();
        foreach ($proxies as $className => $aspects) {
            // 代理文件的存放位置
            $proxyFiles[$className] = $this->putProxyFile($ast, $className);
        }
        return $proxyFiles;
    }

    protected function putProxyFile(Ast $ast, $className)
    {
        $proxyFilePath = $this->getProxyFilePath($className);
        $modified = true;
        if (file_exists($proxyFilePath)) {
            $modified = $this->isModified($className, $proxyFilePath);
        }

        if ($modified) {
            $code = $ast->proxy($className);
            file_put_contents($proxyFilePath, $code);
        }

        return $proxyFilePath;
    }

    protected function isModified(string $className, string $proxyFilePath = null): bool
    {
        $proxyFilePath = $proxyFilePath ?? $this->getProxyFilePath($className);
        $time = $this->filesystem->lastModified($proxyFilePath);
        $origin = $this->classMap[$className];
        if ($time >= $this->filesystem->lastModified($origin)) {
            return false;
        }

        return true;
    }

    protected function getProxyFilePath($className)
    {
        return $this->getProxyDir() . str_replace('\\', '_', $className) . '.proxy.php';
    }

    protected function isMatch(string $rule, string $target): bool
    {
        if (str_contains($rule, '::')) {
            [$rule] = explode('::', $rule);
        }
        if (! str_contains($rule, '*') && $rule === $target) {
            return true;
        }
        $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
        $pattern = "/^{$preg}$/";

        if (preg_match($pattern, $target)) {
            return true;
        }

        return false;
    }

    /**
     * (将composer中的classMap中的类进行逐一检查)×2，分别检查切面定义类中的$classes和$annotations，得出最终要生成代理的类
     * 时间复杂度O(n四次方)
     * @param array $reflectionClassMap
     * @return array $proxies是最终是要生成代理的类，值是：类名（含命名空间） => 切面类名（含命名空间）
     * 返回的数据中似乎没有用到切面类名，这样处理数据的意义是什么？还是说有别的地方用到？
     */
    protected function initProxiesByReflectionClassMap(array $reflectionClassMap = []): array
    {
        // According to the data of AspectCollector to parse all the classes that need proxy.
        $proxies = [];
        if (! $reflectionClassMap) {
            return $proxies;
        }
        // 将composer的classMap的所有类进行一一的切面检查，是否与切面定义文件中$classes中定义的类相匹配，匹配则会生成代理文件
        // 切面定义类名 => 切面定义类中的$classes要切入的类
        $classesAspects = AspectCollector::get('classes', []);
        foreach ($classesAspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                foreach ($reflectionClassMap as $class => $path) {
                    if (! $this->isMatch($rule, $class)) {
                        continue;
                    }
                    $proxies[$class][] = $aspect;
                }
            }
        }

        // 将composer的classMap的所有类进行一一的注解检查，是否存在对应的注解收集器，匹配则会生成代理文件
        foreach ($reflectionClassMap as $className => $path) {
            // 从AnnotationCollector注解搜集器中检查（类、方法、属性），当前$className类是否存在注解（注解收集器）
            // Aggregate the class annotations
            $classAnnotations = $this->retrieveAnnotations($className . '._c');
            // Aggregate all methods annotations
            $methodAnnotations = $this->retrieveAnnotations($className . '._m');
            // 假如IndexController有两个Inject的属性，下面变量的值就像这样：['Hyperf\Di\Annotation\Inject', 'Hyperf\Di\Annotation\Inject']
            // Aggregate all properties annotations
            $propertyAnnotations = $this->retrieveAnnotations($className . '._p');
            $annotations = array_unique(array_merge($classAnnotations, $methodAnnotations, $propertyAnnotations));
            if ($annotations) {
                // 检查$className这个类中的注解收集器到底是属于哪个切面定义的注解
                // 获取切面搜集器集合，例如：\Hyperf\Di\Annotation\Aspect
                // 切面定义类名 => 切面定义类中的annotations定义的注解
                $annotationsAspects = AspectCollector::get('annotations', []);
                foreach ($annotationsAspects as $aspect => $rules) {
                    foreach ($rules as $rule) {
                        foreach ($annotations as $annotation) {
                            if ($this->isMatch($rule, $annotation)) {
                                $proxies[$className][] = $aspect;
                            }
                        }
                    }
                }
            }
        }
        return $proxies;
    }

    protected function retrieveAnnotations(string $annotationCollectorKey): array
    {
        $defined = [];
        $annotations = AnnotationCollector::get($annotationCollectorKey, []);

        foreach ($annotations as $name => $annotation) {
            if (is_object($annotation)) {
                $defined[] = $name;
            } else {
                $defined = array_merge($defined, array_keys($annotation));
            }
        }
        return $defined;
    }
}
