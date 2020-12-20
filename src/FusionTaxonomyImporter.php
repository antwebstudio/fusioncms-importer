<?php
namespace Ant\FusionHelper;

use Fusion\Models\Field;

class FusionTaxonomyImporter extends FusionImporter {
    public $importImages = true;
    public $importCategories = true;
    public $categoryIdMap = [];

    public static function make($configs) {
        $self = new self();
        foreach($configs as $key => $value) {
            $self->{$key} = $value;
        }
        return $self;
    }

    public function setCategoryIdMap($values) {

    }

    public function getCategoryIdMap() {
        return $this->categoryIdMap;
    }

    public function setHandle() {
        parent::setHandle();
    }

    public function isCategory($oldFieldAttribute) {
        $this->after(function($oldModel, $newModel) use($oldFieldAttribute) {
            $oldCategoryIds = $oldModel->{$oldFieldAttribute};
            $this->categoryIdMap[$oldCategoryIds] = $newModel->id;
            echo 'Register '.$oldFieldAttribute.': '.$oldCategoryIds.' => '.$newModel->id."\n";
        });
        return $this;
    }

    public function haveImage($oldFieldAttributeOrValueGetter, $newFieldSetHandle = 'article', $newFieldAttribute = 'image') {
        $this->after(function($oldModel, $newModel) use($oldFieldAttributeOrValueGetter, $newFieldAttribute, $newFieldSetHandle) {
            if (!$this->importImages) return;

            $targetField = self::getField($newFieldSetHandle, $newFieldAttribute);
            
            $filePath = is_callable($oldFieldAttributeOrValueGetter) ? call_user_func_array($oldFieldAttributeOrValueGetter, [$oldModel]) : $oldModel->{$oldFieldAttributeOrValueGetter};

            if (isset($filePath) && trim($filePath) != '') {
                $directoryPath = str_replace('-', '/', $oldModel->story_date);
                $file = FusionImporter::saveFile($filePath, $directoryPath);
                FusionImporter::assignFilesToModel([$file], $newModel, $targetField);
            }
        });

        return $this;
    }

    public function haveCategory($oldFieldAttribute, $newFieldAttribute = 'category', $newFieldSetHandle = 'article') {
        $this->after(function($oldModel, $newModel) use($oldFieldAttribute, $newFieldAttribute, $newFieldSetHandle) {
            if (!$this->importCategories) return;

            $targetField = self::getField($newFieldSetHandle, $newFieldAttribute);
            $oldCategoryIds = $oldModel->{$oldFieldAttribute};

            if (isset($newModel->{$newFieldAttribute}) && isset($oldCategoryIds) && $oldCategoryIds) {
                $newCategoryId = $this->categoryIdMap[$oldCategoryIds];

                if (isset($newCategoryId)) {
                    $oldValues = $newModel->{$newFieldAttribute}->pluck('id');
                    $newModel->{$newFieldAttribute}()->detach($oldValues);
                    $newModel->{$newFieldAttribute}()->attach([
                        $newCategoryId => ['field_id' => $targetField->id],
                    ]);

                    echo 'Category '.$newCategoryId.' assigned to '.get_class($newModel).' ID: '.$newModel->id."\n";
                }
            }
        });
        return $this;
    }

    public function haveParentCategory($oldFieldAttribute, $newFieldAttribute = 'parent_category', $newFieldSetHandle = 'category') {
        return $this->haveCategory($oldFieldAttribute, $newFieldAttribute, $newFieldSetHandle);
    }
}