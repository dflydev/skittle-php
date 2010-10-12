<?php

interface skittle_IResourceLocator {
    /**
     * Find a target file
     * @param string $target
     * @param bool $realPath
     * @return string
     */
    public function find($target, $realPath = false);
}

?>