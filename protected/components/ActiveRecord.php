<?php

class ActiveRecord extends CActiveRecord
{

    /**
     * @author QuanNT
     * Update created_at field and updated_at field
     * for all models before save to database.
     */
    public function beforeSave()
    {
        if ($this->isNewRecord || !$this->created_at) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        $this->update_at = date('Y-m-d H:i:s');
        return parent::beforeSave();
    }

}

?>