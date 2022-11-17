<?php

declare(strict_types=1);

namespace App\PictureManager;

use Intervention\Image\PictureManager as Intervention;

class ImageStatus
{
    /**
     * @var string|null
     */
    protected ?string $image = null;

    /**
     * @var Configuration $config
     */
    protected Configuration $config;

    /**
     * File hash
     *
     * @var string|null
     */
    protected ?string $hash = null;

    /**
     * Saved image and images other size in cache
     *
     * @var ?array $file
     */
    protected ?array $file = null;

    /**
     * Configuration constructor.
     * @param string|null $image
     * @param Configuration|null $config
     */
    public function __construct(string $image = null, Configuration $config = null)
    {
        if (is_null($config)) {
            $config = new Configuration();
        }
        $this->config = $config;
        if (!is_null($image)) {
            $this->image = $image;
            $this->hash = (new Hash)->getHashString($image);
            $this->file = $this->config->transport()->getByHash($this->hash);
        }
    }

    /**
     * @param string|null $title
     * @param string|null $name
     * @param string|null $path
     * @return ImageStatus
     */
    public function save(string $title = null, string $name = null, string $path = null): ImageStatus
    {
        if (is_null($this->image) || (isset($this->file['disk']) && file_exists($this->file['disk']))) {
            return $this;
        }
        $update = isset($this->file['disk']) && !file_exists($this->file['disk']);
        $fileHandler = new File();
        if ($update) {
            $idImage = $this->file['id'];
            $disk = str_replace($this->file['name'], null, $this->file['disk']);
            $hash = $this->file['hash'];
            $name = $this->file['name'];
            $title = $this->file['title'];
            $path = $this->file['path'];
            $url = $this->file['url'];
        } else {
            // args
            $extension = '.' . $fileHandler->getExtensionFromString($this->image, $this->config->getMimeTypes());
            $hash = (new Hash)->getHashString($this->image);
            $name = $fileHandler->getNameImage($name, $hash, $extension);
            $pathDate = !is_null($path) ? $path : date('/Y/m/d/H/m/');
            $title = !is_null($title) ? $title : null;
            $disk = $this->config->getPathDisk() . $pathDate;
            $disk = (str_replace('//', '/', $disk));
            $path = (str_replace('//', '/', $pathDate . $name));
            $url = $this->config->getPathUrl() . $pathDate . $name;
        }
        // create path
        try {
            $makeDirectory = $fileHandler->makeDirectory($disk);
        } catch (Exceptions\MakeDirectoryException $e) {
            return $this;
        }
        // save image to file
        if (isset($makeDirectory)) {
            $disk .= $name;
            $fileHandler->save($disk, $this->image);
            $this->file = [
                'hash' => $hash,
                'title' => $title,
                'name' => $name,
                'disk' => $disk,
                'path' => $path,
                'url' => $url,
                'cache' => [],
            ];
        }
        // Start Pipes
        $this->pipes();
        // save image cache size
        $this->saveResize();
        // trasport save
        if ($update) {
            $this->file['id'] = $idImage;
            $update = $this->file;
            unset($update['disk']);
            unset($update['url']);
            $this->config->transport()->update($update);
        } else {
            $this->file['id'] = $this->config->transport()->save($this->getImage());
        }
        return $this;
    }

    /**
     * @return void
     */
    protected function pipes(): void
    {
        foreach ($this->config->getPipes() as $className) {
            $class = new $className;
            call_user_func($class, $this->file);
        }
    }

    /**
     * @return $this
     */
    protected function saveResize(): ImageStatus
    {
        if (!is_null($this->file) && is_array($this->file)) {
            // drop old cache image
            if (isset($this->file['cache']) && is_array($this->file['cache'])) {
                foreach ($this->file['cache'] as $image) {
                    if (file_exists($image['disk'])) {
                        unlink($image['disk']);
                    }
                }
            }
            // create cache image
            foreach ($this->config->getImageSize() as $size) {
                $width = $size[0] > 0 ? intval($size[0]) : null;
                $height = $size[1] > 0 ? intval($size[1]) : null;
                $key = $width . 'x' . $height;
                $name = $key . '-' . $this->file['name'];
                $disk = str_replace($this->file['name'], $name, $this->file['disk']);
                $url = str_replace($this->file['name'], $name, $this->file['url']);
                $path = str_replace($this->file['name'], $name, $this->file['path']);
                (new Intervention())->make($this->file['disk'])
                    ->fit($width, $height)
                    ->save($disk);
                if (file_exists($disk)) {
                    $this->file['cache'][$key] = [
                        'disk' => $disk,
                        'url' => $url,
                        'path' => $path,
                    ];
                }
            }
            // Update cache size image info in db
            $this->config->transport()->update($this->getImage());
        }
        return $this;
    }

    /**
     * @param array|null $update
     * @return $this
     */
    public function update(array $update = null): ImageStatus
    {
        if (is_null($this->file) || !is_array($this->file) || !isset($this->file['id'])) {
            return $this;
        }
        $image = $this->getImage();
        if (is_null($update)) {
            $update = [];
        }
        $update['width'] = $image['width'];
        $update['height'] = $image['height'];
        $update['type'] = $image['type'];
        $update['size'] = $image['size'];
        $update['id'] = $this->file['id'];
        $this->config->transport()->update($update);
        return $this;
    }

    /**
     * Delete image server and use information
     *
     * @return null|$this
     */
    public function drop(): ?ImageStatus
    {
        if (is_null($this->file) || !is_array($this->file)) {
            return $this;
        }
        // drop file riginal
        if (isset($this->file['disk']) && file_exists($this->file['disk'])) {
            unlink($this->file['disk']);
        }
        // drop file cache
        if (isset($this->file['cache']) && is_array($this->file['cache'])) {
            foreach ($this->file['cache'] as $img) {
                if (file_exists($img['disk'])) {
                    unlink($img['disk']);
                }
            }
        }
        $this->config->transport()->dropImage($this->file['id']);
        return null;
    }

    /**
     * @return array|null
     */
    public function getImage(): ?array
    {
        if (is_null($this->file) || !is_array($this->file)) {
            return null;
        }
        if (file_exists($this->file['disk'])) {
            $size = filesize($this->file['disk']);
            $imageInfo = getimagesize($this->file['disk']);
            $this->file['width'] = $imageInfo[0];
            $this->file['height'] = $imageInfo[1];
            $this->file['type'] = $imageInfo['mime'];
            $this->file['size'] = $size;
        } else {
            $this->file['width'] = 0;
            $this->file['height'] = 0;
            $this->file['type'] = 0;
            $this->file['size'] = 0;
        }
        return $this->file;
    }

    /**
     * View Image
     *
     * @return mixed
     */
    public function response()
    {
        return (new Intervention)->make($this->image)->response();
    }
}