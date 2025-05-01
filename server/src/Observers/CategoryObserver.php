<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\Models\Category;
use Fleetbase\Models\CustomField;

class CategoryObserver
{
    /**
     * Handle the Category "deleted" event.
     *
     * @return void
     */
    public function deleted(Category $category)
    {
        // if the category deleted was for "custom_field_group" - delete the custom fields belonging
        CustomField::where('category_uuid', $category->uuid)->delete();
    }
}
