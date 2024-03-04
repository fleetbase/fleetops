<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Support\Auth;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Str;

class OrderConfig extends Model
{
    use HasUuid;
    use Searchable;
    use HasMetaAttributes;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'order_configs';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'author_uuid',
        'category_uuid',
        'icon_uuid',
        'name',
        'namespace',
        'description',
        'key',
        'status',
        'version',
        'core_service',
        'tags',
        'flow',
        'entities',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'tags' => Json::class,
        'flow' => Json::class,
        'entities' => Json::class,
        'meta' => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Bootstraps the model and its events.
     *
     * This method overrides the default Eloquent model boot method
     * to add a custom 'creating' event listener. This listener is used
     * to set default values when a new model instance is being created.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->namespace = static::createNamespace($model->name);
            $model->version = '0.0.1';
            $model->status = 'private';
            $model->key = Str::slug($model->name);
        });
    }

    /**
     * Creates a namespaced string based on the provided name.
     *
     * This method generates a namespaced string using the company's name
     * retrieved from the authenticated user's company, followed by a fixed
     * segment ':order-config:', and the provided name. This is used to
     * create a unique namespace for each model instance.
     *
     * @param string $name The name to be included in the namespace.
     * @return string The generated namespaced string.
     */
    public static function createNamespace(string $name): string
    {
        $company = Auth::getCompany();
        return Str::slug($company->name) . ':order-config:' . Str::slug($name);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(\Fleetbase\Models\User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(\Fleetbase\Models\Category::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function icon()
    {
        return $this->belongsTo(\Fleetbase\Models\File::class);
    }

    public function currentActivity() {}
    public function nextActivity() {}
    public function previousActivity() {}
}
