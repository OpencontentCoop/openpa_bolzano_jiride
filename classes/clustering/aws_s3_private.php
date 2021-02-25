<?php

class AWSS3Private extends AWSS3Public
{
    protected $acl = 'private';

    public function applyServerUri($filePath)
    {
        return $filePath;
    }
}
