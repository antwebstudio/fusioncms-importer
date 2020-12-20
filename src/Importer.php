<?php
namespace Ant\FusionHelper;

class Importer {
    protected $_handler;
    protected $_after = [];
    protected $_slugCount;

    public static function try($try, $callback, $sleep = 1, $throwExceptionOnFailed = false) {
        $tried = 0;
        $failed = false;

        do {
            echo 'Trying'."\n";
            $tried++;

            try {
                call_user_func_array($callback, []);
                $failed = false;
            } catch (\Exception $ex) {
                $failed = true;

                if ($throwExceptionOnFailed && $tried >= $try) {
                    throw $ex;
                }
                echo 'Retry'."\n";
                if ($sleep) sleep($sleep);
            }
        } while($tried < $try && $failed);

        return !$failed;
    }

    public function setHandler($callback) {
        $this->_handler = $callback;
        return $this;
    }

    public function after($callback) {
        $this->_after[] = $callback;
        return $this;
    }

    public function import($sourceRows) {
        foreach ($sourceRows as $row) {
            $newModel = call_user_func_array($this->_handler, [$row]);
            foreach($this->_after as $after) {
                call_user_func_array($after, [$row, $newModel]);
            }
        }
    }

    public function generateSlug($slug) {
        $slug = html_entity_decode(strip_tags($slug));
        $slug = str_replace([' '], '', $slug);
        if (isset($this->_slugCount[$slug])) {
            $this->_slugCount[$slug]++;
            $slug = $slug.'-'.$this->_slugCount[$slug];
        } else {
            $this->_slugCount[$slug] = 0;
        }
        return $slug;
    }
}
