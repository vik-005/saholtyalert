<?php

namespace App;

use App\AssetMapper\PatchedAssetMapperRepository;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('asset_mapper.repository')) {
            $container->getDefinition('asset_mapper.repository')
                ->setClass(PatchedAssetMapperRepository::class);
        }
    }
}
