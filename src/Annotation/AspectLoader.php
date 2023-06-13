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

use Hyperf\Di\ReflectionManager;
use ReflectionProperty;

class AspectLoader
{
    /**
     * 读取切面的配置数据
     * 对应文档提到的两个public属性：classes要切入的类或trait、annotations要切入的注解，priority属性文档没提到，应该是预留的
     * 文档：https://hyperf.wiki/3.0/#/zh-cn/aop?id=%e5%ae%9a%e4%b9%89%e5%88%87%e9%9d%a2aspect
     * Load classes annotations and priority from aspect without invoking their constructor.
     */
    public static function load(string $className): array
    {
        $reflectionClass = ReflectionManager::reflectClass($className);
        $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);
        $instanceClasses = $instanceAnnotations = [];
        $instancePriority = null;
        foreach ($properties as $property) {
            if ($property->getName() === 'classes') {
                $instanceClasses = ReflectionManager::getPropertyDefaultValue($property);
            } elseif ($property->getName() === 'annotations') {
                $instanceAnnotations = ReflectionManager::getPropertyDefaultValue($property);
            } elseif ($property->getName() === 'priority') {
                $instancePriority = ReflectionManager::getPropertyDefaultValue($property);
            }
        }

        return [$instanceClasses, $instanceAnnotations, $instancePriority];
    }
}
