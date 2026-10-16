<?php

namespace App\AssetMapper;

use Symfony\Component\AssetMapper\AssetMapperRepository;

/**
 * Extends Symfony's AssetMapperRepository to fix Windows path normalization
 * where mixed slashes and case sensitivity break findLogicalPath().
 */
class PatchedAssetMapperRepository extends AssetMapperRepository
{
    public function findLogicalPath(string $filesystemPath): ?string
    {
        if (!is_file($filesystemPath)) {
            return null;
        }

        $normFilesystemPath = str_replace('\\', '/', $filesystemPath);

        foreach ($this->allDirectories() as $path => $namespace) {
            $normPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
            $match = ('\\' === \DIRECTORY_SEPARATOR)
                ? (0 === strncasecmp($normFilesystemPath, $normPath, \strlen($normPath)))
                : str_starts_with($normFilesystemPath, $normPath);

            if (!$match) {
                continue;
            }

            $logicalPath = substr($normFilesystemPath, \strlen($normPath));

            if ('' !== $namespace) {
                $logicalPath = $namespace . '/' . ltrim($logicalPath, '/\\');
            }

            return ltrim(str_replace('\\', '/', $logicalPath), '/\\');
        }

        return null;
    }
}
