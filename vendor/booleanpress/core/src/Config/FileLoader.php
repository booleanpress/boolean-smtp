<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Config;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FileLoader
{
    /**
     * All of the loaded configuration files.
     *
     * @var array
     */
    protected $files = [];

    /**
     * The default configuration path.
     *
     * @var string
     */
    protected $defaultPath;

    /**
     * Create a new file loader instance.
     *
     * @param  string  $defaultPath
     * @return void
     */
    public function __construct(string $defaultPath)
    {
        $this->defaultPath = $defaultPath;
    }

    /**
     * Load the given configuration group.
     *
     * @return array
     */
    public function load()
    {
        $items = [];

        if (!is_dir($this->defaultPath)) {
            return $items;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->defaultPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $key = $this->getNestedKey($file);
                $items[$key] = require $file->getPathname();
            }
        }

        return $items;
    }

    /**
     * Get the nested key for a given file.
     *
     * @param  SplFileInfo  $file
     * @return string
     */
    protected function getNestedKey(SplFileInfo $file)
    {
        $directory = $file->getPath();

        if ($nested = trim(str_replace($this->defaultPath, '', $directory), DIRECTORY_SEPARATOR)) {
            $nested = str_replace(DIRECTORY_SEPARATOR, '.', $nested) . '.';
        }

        return $nested . $file->getBasename('.php');
    }
}
