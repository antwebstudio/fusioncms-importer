
<?php
namespace Ant\FusionHelper;

use Fusion\Models\Field;

class FusionTaxonomyImporter extends FusionImporter {
    protected $importImages = true;
    protected $importCategories = true;
    protected $categoryIdMap = [];

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

            $targetField = $this->getField($newFieldSetHandle, $newFieldAttribute);
            
            $filePath = is_callable($oldFieldAttributeOrValueGetter) ? call_user_func_array($oldFieldAttributeOrValueGetter, [$oldModel]) : $oldModel->{$oldFieldAttributeOrValueGetter};

            if (isset($filePath) && trim($filePath) != '') {
                $file = FusionImporter::saveFile($filePath);
                FusionImporter::saveFilesForModel([$file], $newModel, $targetField);
            }
        });

        return $this;
    }

    public function haveCategory($oldFieldAttribute, $newFieldAttribute = 'category', $newFieldSetHandle = 'article') {
        $this->after(function($oldModel, $newModel) use($oldFieldAttribute, $newFieldAttribute, $newFieldSetHandle) {
            if (!$this->importCategories) return;

            $targetField = $this->getField($newFieldSetHandle, $newFieldAttribute);
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

    protected function getField($fieldSetHandle, $fieldAttribute) {
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
}