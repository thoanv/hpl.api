<?php
namespace Api\Test;
use CFile;
class Test
{
    public function __construct()
    {
        echo 1;
    }

    public function index()
    {
        CFile::CleanCache();
    }
}
