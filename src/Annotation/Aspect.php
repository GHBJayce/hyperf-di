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

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class Aspect extends AbstractAnnotation
{
    public function __construct(public array $classes = [], public array $annotations = [], public ?int $priority = null)
    {
    }

    public function collectClass(string $className): void
    {
        parent::collectClass($className);
        $this->collect($className);
    }

    /**
     * 收集$className（切面定义文件）的切面配置数据，将数据进行处理然后存储到切面元数据收集器中
     * @param string $className
     * @return void
     */
    protected function collect(string $className)
    {
        // 读取$className这个类的切面配置数据
        [$instanceClasses, $instanceAnnotations, $instancePriority] = AspectLoader::load($className);

        // 以下均是切面配置数据合并处理，当前$className类的切面配置数据优先级高于当前Aspect类的配置
        // Classes
        $classes = $this->classes;
        $classes = $instanceClasses ? array_merge($classes, $instanceClasses) : $classes;
        // Annotations
        $annotations = $this->annotations;
        $annotations = $instanceAnnotations ? array_merge($annotations, $instanceAnnotations) : $annotations;
        // Priority
        $annotationPriority = $this->priority;
        $propertyPriority = $instancePriority ?: null;
        if (! is_null($annotationPriority) && ! is_null($propertyPriority) && $annotationPriority !== $propertyPriority) {
            throw new InvalidArgumentException('Cannot define two difference priority of Aspect.');
        }
        $priority = $annotationPriority ?? $propertyPriority;
        // 将切面配置数据以$className => 配置数据的方式存到切面收集器中
        // Save the metadata to AspectCollector
        AspectCollector::setAround($className, $classes, $annotations, $priority);
    }
}
