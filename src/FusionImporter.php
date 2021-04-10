<?php
namespace Ant\FusionHelper;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Fusion\Models\Field;
use Fusion\Models\Directory;
use Fusion\Models\File as FileModel;
use Fusion\Models\Fieldset;
use Fusion\Models\Section;

class FusionImporter extends Importer {
    protected static $savedFilePath;

    protected static function slug($string, $separator = '-') {
        $re = "/(\\s|\\".$separator.")+/mu";
        $str = @trim($string);
        $subst = $separator;
        $result = preg_replace($re, $subst, $str);
        return $result;
    }

    public static function getField($fieldSetHandle, $fieldAttribute) {
        $fieldset = Fieldset::where('handle', $fieldSetHandle)->get()->first();
        if (!isset($fieldset)) {
            throw new \Exception('No fieldset with handle "'.$fieldSetHandle.'".');
        }
        $section = Section::select('id')->where('fieldset_id', $fieldset->id);
        
        //dd($section->get()->map(function($model) { return $model->id; }));
        //dd($parentCategoryAttribute);
        
        $field = Field::where('handle', $fieldAttribute)
            ->whereIn('section_id', $section)
            ->get()
            ->first();
        
        if (!isset($field)) {
            throw new \Exception('Field with handle "'.$fieldAttribute.'" in fieldset "'.$fieldSetHandle.'" is not exist.');
        }

        return $field;
    }

    public static function isFileSaved($filePath) {
        return self::getSavedFileUid($filePath) != null;
    }

    public static function getFileUid($filePath) {
        return self::getSavedFileUid($filePath);
    }

    /**
     * return [$path => $uid]
     */
    protected static function getSavedFiles() {
        if (!isset(self::$savedFilePath)) {
            self::$savedFilePath = [];

            $files = Storage::disk('public')->files('files');
            foreach ($files as $file) {
                preg_match('/files\/([a-z0-9]{13,15})-(.+)/', $file, $matches);

                $path = $matches[2];
                self::$savedFilePath[$path] = $matches[1];
            }
        }
        return self::$savedFilePath;
    }

    public static function getSavedFileUid($filePath) {
        $savedFiles = self::getSavedFiles();
        return $savedFiles[$filePath] ?? null;
    }

    public static function validateFilePath($filePath) {
        return isset($filePath) && trim($filePath) != '';
    }

    public static function getDirectory($directory, $parent = null) {
        if (!is_object($directory)) {
            $seperator = '/';

            if (strpos($directory, $seperator) !== false) {
                $parts = explode($seperator, $directory);
                $directory = $parts[count($parts) - 1];
                array_pop($parts);
                $parentPath = implode($seperator, $parts);
                $parent = self::getDirectory($parentPath);

                return self::getDirectory($directory, $parent);
            } else {
                return Directory::firstOrCreate([
                    'name' => $directory,
                    'slug' => static::slug($directory),
                    'parent_id' => $parent->id ?? 0,
                ]);
            }
        }

        return $directory;
    }

    public static function trimFilePath($filePath) {
        if (strpos($filePath, '?') !== false) {
            return substr($filePath, 0, strpos($filePath, '?'));
        }
        return $filePath;
    }

    public static function saveFile($filePath, $directory = null) {
        $filePath = self::trimFilePath($filePath);
        if (!static::validateFilePath($filePath)) {
            throw new \Exception('Invalid file path: '.$filePath);
        }
        $storage = Storage::disk('public');
        $uuid = unique_id();
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $name = urldecode($name);
        $extension = pathinfo(parse_url($filePath, PHP_URL_PATH), PATHINFO_EXTENSION);
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
            list($width, $height) = getimagesize($filePath);
        }

        $directory = self::getDirectory($directory ?? $name);

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

    public static function assignFilesToModel($files, $model, Field $field)
    {
        if (isset($files)) {
            $oldValues = $model->{$field->handle}->pluck('id');
            $newValues = collect($files)
                ->mapWithKeys(function ($file, $key) use ($field) {
                    $model = $file;

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