<?php

use Aws\S3\Exception\S3Exception;

class AWSS3Public extends AWSS3Abstract
{
    protected $acl = 'public-read';

    /**
     * Copies the local file $filePath to DFS under the same name, or a new name
     * if specified
     *
     * @param string $srcFilePath Local file path to copy from
     * @param bool|string $dstFilePath
     *        Optional path to copy to. If not specified, $srcFilePath is used
     *
     * @return bool
     */
    public function copyToDFS($srcFilePath, $dstFilePath = false)
    {
        try {
            $this->s3client->putObject(
                array(
                    'Bucket' => $this->bucket,
                    'Key' => $dstFilePath ?: $srcFilePath,
                    'SourceFile' => $srcFilePath,
                    'ACL' => $this->acl
                )
            );
            return true;
        } catch (S3Exception $e) {
            eZDebug::writeError($e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function getFile($srcFilePath)
    {
        try {
            $this->s3client->getObject(
                array(
                    'Bucket' => $this->bucket,
                    'Key' => $srcFilePath,
                )
            );
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }
}
