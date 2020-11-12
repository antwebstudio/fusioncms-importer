<?php
namespace Ant\FusionHelper;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Fusion\Models\Field;
use Fusion\Models\Directory;
use Fusion\Models\File as FileModel;

class FusionImporter extends Importer {
    protected static $savedFilePath;

    protected static function slug($string, $separator = '-') {
        $re = "/(\\s|\\".$separator.")+/mu";
        $str = @trim($string);
        $subst = $separator;
        $result = preg_replace($re, $subst, $str);
        return $result;
    }

    public static function getFileUid($filePath) {
        if (!isset(self::$savedFilePath)) {
            self::$savedFilePath = [];
            $files = Storage::disk('public')->files('files');
            foreach ($files as $file) {
                preg_match('/files\/([a-z0-9]{13,15})-(.+)/', $file, $matches);

                $path = $matches[2];
                self::$savedFilePath[$path] = $matches[1];
            }
        }

        return self::$savedFilePath[$filePath] ?? null;
    }

    public static function validateFilePath($filePath) {
        return isset($filePath) && trim($filePath) != '';
    }

    public static function saveFile($filePath) {
        if (!static::validateFilePath($filePath)) {
            throw new \Exception('Invalid file path: '.$filePath);
        }
        $storage = Storage::disk('public');
        $uuid = unique_id();
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $name = urldecode($name);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $location = "files/{$uuid}-{$name}.{$extension}";

        $tried = 0;
        $failed = false;
        do {
            try {
                $savedFile = $storage->putFileAs('', $filePath, $location);
                $failed = false;
            } catch (\Exception $ex) {
                $tried++;
                $failed = true;
                
                if ($tried > 5) {
                    throw $ex;
                }
                echo 'Retry'."\n";
                sleep(3);
            }
        } while($failed);

        $bytes = $storage->size($savedFile);
        $mimetype = $storage->mimetype($savedFile);
        $filetype = strtok($mimetype, '/');
        if ($filetype == 'image') {
            list($width, $height) = getimagesize($storage->path($savedFile));
        }

        $directory = Directory::firstOrCreate([
            'name' => $name,
            'slug' => static::slug($name),
        ]);

        $model = FileModel::create([
            'directory_id' => $directory->id,
            'uuid'         => $uuid,
            'name'         => $name,
            'extension'    => $extension,
            'bytes'        => $bytes,
            'mimetype'     => $mimetype,
            'location'     => $location,
            'width'        => $width ?? null,
            'height'       => $height ?? null,
        ]);

        return $model;
    }

    public static function saveFilesForModel($files, $model, Field $field)
    {
        if (isset($files)) {
            //$files     = request()->file($field->handle);
            $directory = Directory::firstOrCreate([
                'name' => ($name = $field->settings['directory'] ?? 'uploads'),
                'slug' => Str::slug($name),
            ]);
            
            $oldValues = $model->{$field->handle}->pluck('id');
            $newValues = collect($files)
                ->mapWithKeys(function ($file, $key) use ($field, $directory) {
                    if ($file instanceof FileModel) {
                        $model = $file;
                    } else {
                        $width = $file['width'] ?? null;
                        $height = $file['height'] ?? null;
                        $filePath = $file['url'];

                        $uuid = unique_id();
                        $name = pathinfo($filePath, PATHINFO_FILENAME);
                        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                        $mimetype = $file['mime'];
                        $filetype = strtok($mimetype, '/');
                        $location = "files/{$uuid}-{$name}.{$extension}";

                        $this->line($location);
                        $this->line($filePath);

                        $savedFile = Storage::disk('public')->putFileAs('', $filePath, $location);
                        $bytes = Storage::disk('public')->size($savedFile);

                        if ($filetype == 'image') {
                            list($width, $height) = getimagesize($filePath);
                        }

                        $model = FileModel::create([
                            'directory_id' => $directory->id,
                            'uuid'         => $uuid,
                            'name'         => $name,
                            'extension'    => $extension,
                            'bytes'        => $bytes,
                            'mimetype'     => $mimetype,
                            'location'     => $location,
                            'width'        => $width ?? null,
                            'height'       => $height ?? null,
                        ]);
                    }

                    return [$model['id'] => [
                        'field_id' => $field->id,
                        'order'    => $key + 1,
                    ]];
                });

            $model->{$field->handle}()->detach($oldValues);
            $model->{$field->handle}()->attach($newValues);
        }
    }
}
