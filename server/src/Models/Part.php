<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Traits\Maintainable;
use Fleetbase\Models\Alert;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Class Part.
 *
 * Represents a stocked, replaceable component in the fleet management system.
 * Parts can include filters, tires, belts, and other components with quantity and cost tracking.
 */
class Part extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use Maintainable;
    use HasApiModelBehavior;
    use HasSlug;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'parts';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'part';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['sku', 'name', 'manufacturer', 'model', 'serial_number', 'barcode', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['vendor_uuid', 'manufacturer', 'status', 'asset_type', 'warranty_uuid'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'vendor_uuid',
        'warranty_uuid',
        'photo_uuid',
        'sku',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'barcode',
        'description',
        'quantity_on_hand',
        'unit_cost',
        'msrp',
        'asset_type',
        'asset_uuid',
        'type',
        'status',
        'specs',
        'meta',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'vendor_name',
        'warranty_name',
        'photo_url',
        'total_value',
        'is_in_stock',
        'is_low_stock',
        'asset_name',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['vendor', 'warranty', 'photo', 'asset'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'quantity_on_hand' => 'integer',
        'unit_cost'        => 'decimal:2',
        'msrp'             => 'decimal:2',
        'specs'            => Json::class,
        'meta'             => Json::class,
        'asset_type'       => PolymorphicType::class,
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'part';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name', 'sku'])
            ->saveSlugsTo('slug');
    }

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_uuid', 'uuid');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(File::class, 'photo_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    public function asset(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the vendor name.
     */
    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    /**
     * Get the warranty name.
     */
    public function getWarrantyNameAttribute(): ?string
    {
        return $this->warranty?->name;
    }

    /**
     * Get the photo URL.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo?->url;
    }

    /**
     * Get the total value of parts in stock.
     */
    public function getTotalValueAttribute(): float
    {
        return $this->quantity_on_hand * ($this->unit_cost ?? 0);
    }

    /**
     * Check if the part is in stock.
     */
    public function getIsInStockAttribute(): bool
    {
        return $this->quantity_on_hand > 0;
    }

    /**
     * Check if the part is low on stock.
     */
    public function getIsLowStockAttribute(): bool
    {
        $specs             = $this->specs ?? [];
        $lowStockThreshold = $specs['low_stock_threshold'] ?? 5;

        return $this->quantity_on_hand <= $lowStockThreshold;
    }

    /**
     * Get the asset name if associated.
     */
    public function getAssetNameAttribute(): ?string
    {
        if ($this->asset) {
            return $this->asset->name ?? $this->asset->display_name ?? null;
        }

        return null;
    }

    /**
     * Scope to get parts in stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInStock($query)
    {
        return $query->where('quantity_on_hand', '>', 0);
    }

    /**
     * Scope to get parts that are out of stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('quantity_on_hand', '<=', 0);
    }

    /**
     * Scope to get parts with low stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock($query, ?int $threshold = null)
    {
        $threshold = $threshold ?? 5;

        return $query->where('quantity_on_hand', '<=', $threshold)
                    ->where('quantity_on_hand', '>', 0);
    }

    /**
     * Scope to get parts by manufacturer.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByManufacturer($query, string $manufacturer)
    {
        return $query->where('manufacturer', $manufacturer);
    }

    /**
     * Add stock to the part.
     */
    public function addStock(int $quantity, ?string $reason = null): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        $oldQuantity = $this->quantity_on_hand;
        $newQuantity = $oldQuantity + $quantity;

        $updated = $this->update(['quantity_on_hand' => $newQuantity]);

        if ($updated) {
            activity('stock_added')
                ->performedOn($this)
                ->withProperties([
                    'old_quantity'   => $oldQuantity,
                    'added_quantity' => $quantity,
                    'new_quantity'   => $newQuantity,
                    'reason'         => $reason,
                ])
                ->log("Added {$quantity} units to stock");
        }

        return $updated;
    }

    /**
     * Remove stock from the part.
     */
    public function removeStock(int $quantity, ?string $reason = null): bool
    {
        if ($quantity <= 0 || $quantity > $this->quantity_on_hand) {
            return false;
        }

        $oldQuantity = $this->quantity_on_hand;
        $newQuantity = $oldQuantity - $quantity;

        $updated = $this->update(['quantity_on_hand' => $newQuantity]);

        if ($updated) {
            activity('stock_removed')
                ->performedOn($this)
                ->withProperties([
                    'old_quantity'     => $oldQuantity,
                    'removed_quantity' => $quantity,
                    'new_quantity'     => $newQuantity,
                    'reason'           => $reason,
                ])
                ->log("Removed {$quantity} units from stock");

            // Check if stock is now low and create alert if needed
            if ($this->is_low_stock) {
                $this->createLowStockAlert();
            }
        }

        return $updated;
    }

    /**
     * Set the stock quantity.
     */
    public function setStock(int $quantity, ?string $reason = null): bool
    {
        if ($quantity < 0) {
            return false;
        }

        $oldQuantity = $this->quantity_on_hand;
        $updated     = $this->update(['quantity_on_hand' => $quantity]);

        if ($updated) {
            activity('stock_adjusted')
                ->performedOn($this)
                ->withProperties([
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $quantity,
                    'adjustment'   => $quantity - $oldQuantity,
                    'reason'       => $reason,
                ])
                ->log("Stock adjusted from {$oldQuantity} to {$quantity}");
        }

        return $updated;
    }

    /**
     * Create a low stock alert.
     */
    protected function createLowStockAlert(): void
    {
        // Check if there's already an open low stock alert
        $existingAlert = Alert::where('subject_type', static::class)
            ->where('subject_uuid', $this->uuid)
            ->where('type', 'low_stock')
            ->where('status', 'open')
            ->first();

        if (!$existingAlert) {
            Alert::create([
                'company_uuid' => $this->company_uuid,
                'type'         => 'low_stock',
                'severity'     => 'medium',
                'status'       => 'open',
                'subject_type' => static::class,
                'subject_uuid' => $this->uuid,
                'message'      => "Part '{$this->name}' (SKU: {$this->sku}) is low on stock ({$this->quantity_on_hand} remaining)",
                'context'      => [
                    'part_name'           => $this->name,
                    'sku'                 => $this->sku,
                    'current_quantity'    => $this->quantity_on_hand,
                    'low_stock_threshold' => $this->specs['low_stock_threshold'] ?? 5,
                ],
                'triggered_at' => now(),
            ]);
        }
    }

    /**
     * Check if the part is compatible with an asset.
     */
    public function isCompatibleWith(Asset $asset): bool
    {
        $specs            = $this->specs ?? [];
        $compatibleAssets = $specs['compatible_assets'] ?? [];

        if (empty($compatibleAssets)) {
            return true; // No restrictions
        }

        // Check by asset type
        if (in_array($asset->type, $compatibleAssets)) {
            return true;
        }

        // Check by make/model
        $assetIdentifier = $asset->make . ' ' . $asset->model;
        if (in_array($assetIdentifier, $compatibleAssets)) {
            return true;
        }

        return false;
    }

    /**
     * Get the reorder point for this part.
     */
    public function getReorderPoint(): int
    {
        $specs = $this->specs ?? [];

        return $specs['reorder_point'] ?? $specs['low_stock_threshold'] ?? 5;
    }

    /**
     * Get the reorder quantity for this part.
     */
    public function getReorderQuantity(): int
    {
        $specs = $this->specs ?? [];

        return $specs['reorder_quantity'] ?? 10;
    }

    /**
     * Check if the part needs to be reordered.
     */
    public function needsReorder(): bool
    {
        return $this->quantity_on_hand <= $this->getReorderPoint();
    }

    /**
     * Get the estimated cost for a quantity of this part.
     */
    public function getEstimatedCost(int $quantity = 1, bool $useRetailPrice = false): float
    {
        $price = $useRetailPrice ? ($this->msrp ?? $this->unit_cost) : $this->unit_cost;

        return $quantity * ($price ?? 0);
    }
}
