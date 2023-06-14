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

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

class InjectAspect extends AbstractAspect
{
    public array $annotations = [
        Inject::class,
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // 正如下面注释所说，仅仅是为了能够生成代理文件而必须要建立该切面，为什么一定要让注入生成代理文件，不生成行不行？
        // Do nothing, just to mark the class should be generated to the proxy classes.
        return $proceedingJoinPoint->process();
    }
}
