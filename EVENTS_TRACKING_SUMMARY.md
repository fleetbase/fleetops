# FleetOps Event Tracking Implementation Summary

## Overview
Added event tracking to FleetOps controllers using the `events` service from `@fleetbase/ember-core`.

## Controllers Updated

### Total: 30 controllers with active save tasks

#### Analytics (2)
- ✅ `analytics/reports/index/new.js` - Track report created
- ✅ `analytics/reports/index/edit.js` - Track report updated

#### Connectivity (6)
- ✅ `connectivity/devices/index/new.js` - Track device created
- ✅ `connectivity/devices/index/edit.js` - Track device updated
- ✅ `connectivity/sensors/index/new.js` - Track sensor created
- ✅ `connectivity/sensors/index/edit.js` - Track sensor updated
- ✅ `connectivity/telematics/index/new.js` - Track telematics created
- ✅ `connectivity/telematics/index/edit.js` - Track telematics updated

#### Management (16)
- ✅ `management/contacts/customers/new.js` - Track customer created
- ✅ `management/contacts/customers/edit.js` - Track customer updated
- ✅ `management/contacts/index/new.js` - Track contact created
- ✅ `management/contacts/index/edit.js` - Track contact updated
- ✅ `management/drivers/index/new.js` - Track driver created
- ✅ `management/drivers/index/edit.js` - Track driver updated
- ✅ `management/fleets/index/new.js` - Track fleet created
- ✅ `management/fleets/index/edit.js` - Track fleet updated
- ✅ `management/fuel-reports/index/new.js` - Track fuel report created
- ✅ `management/fuel-reports/index/edit.js` - Track fuel report updated
- ✅ `management/issues/index/new.js` - Track issue created
- ✅ `management/issues/index/edit.js` - Track issue updated
- ✅ `management/places/index/new.js` - Track place created
- ✅ `management/places/index/edit.js` - Track place updated
- ✅ `management/vehicles/index/new.js` - Track vehicle created
- ✅ `management/vehicles/index/edit.js` - Track vehicle updated
- ✅ `management/vendors/index/new.js` - Track vendor created
- ✅ `management/vendors/index/edit.js` - Track vendor updated

#### Operations (4)
- ✅ `operations/orders/index/new.js` - Track order created
- ✅ `operations/service-rates/index/new.js` - Track service rate created
- ✅ `operations/service-rates/index/edit.js` - Track service rate updated

## Components Updated

### Total: 1 component

- ✅ `customer/create-order-form.js` - Added standard event tracking alongside existing custom events

## Controllers Skipped (Empty/No Save Task)

### Maintenance (6)
- ⏭️ `maintenance/equipment/index/new.js` - Empty controller
- ⏭️ `maintenance/equipment/index/edit.js` - Empty controller
- ⏭️ `maintenance/parts/index/new.js` - Empty controller
- ⏭️ `maintenance/parts/index/edit.js` - Empty controller
- ⏭️ `maintenance/work-orders/index/new.js` - Empty controller
- ⏭️ `maintenance/work-orders/index/edit.js` - Empty controller

### Management (2)
- ⏭️ `management/vendors/integrated/new.js` - Empty controller
- ⏭️ `management/vendors/integrated/edit.js` - Empty controller

### Operations (1)
- ⏭️ `operations/routes/index/new.js` - No save task

## Implementation Pattern

### NEW Controllers (Create)
```javascript
@service events;

@task *save(resource) {
    try {
        yield resource.save();
        this.events.trackResourceCreated(resource);
        // ... rest of code
    } catch (err) {
        this.notifications.serverError(err);
    }
}
```

### EDIT Controllers (Update)
```javascript
@service events;

@task *save(resource) {
    try {
        yield resource.save();
        this.events.trackResourceUpdated(resource);
        // ... rest of code
    } catch (err) {
        this.notifications.serverError(err);
    }
}
```

### Component (Dual Tracking)
```javascript
@service events;

yield order.save();

// Standard event tracking
this.events.trackResourceCreated(order);

// Keep existing custom event
this.universe.trigger('fleet-ops.order.created', order);
```

## Events Emitted

For each tracked resource, two events are emitted:

1. **Generic event**: `resource.created` or `resource.updated`
2. **Specific event**: `{model}.created` or `{model}.updated`

### Examples
- `resource.created` + `order.created`
- `resource.updated` + `vehicle.updated`
- `resource.created` + `driver.created`
- `resource.updated` + `place.updated`

## Resource Types Tracked

- `report` (analytics)
- `device`, `sensor`, `telematic` (connectivity)
- `customer`, `contact`, `driver`, `fleet`, `fuel-report`, `issue`, `place`, `vehicle`, `vendor` (management)
- `order`, `service-rate` (operations)

## Integration with Internals

These events will be consumed by the `internals` analytics-listener for PostHog tracking:

```javascript
// In internals/addon/instance-initializers/analytics-listener.js
universe.on('order.created', (order) => {
    posthog.trackEvent('order_created', {
        order_id: order.id,
        // ... other properties
    });
});
```

## Testing

To verify events are firing:

1. **In development console:**
```javascript
// Listen to all events
window.universe = owner.lookup('service:universe');
universe.on('resource.created', (resource) => console.log('Created:', resource));
universe.on('resource.updated', (resource) => console.log('Updated:', resource));
```

2. **Check PostHog (in cloud):**
- Events should appear in PostHog Activity tab
- Event names will be snake_case: `order_created`, `vehicle_updated`, etc.

## Dependencies

Requires:
- `@fleetbase/ember-core` with `events` service
- `@fleetbase/internals` with analytics-listener (for PostHog tracking in cloud)
