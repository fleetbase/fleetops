# FleetOps Driver Scheduling Module

This module implements driver scheduling with Hours of Service (HOS) compliance for FleetOps.

## Features

- **Fleet-Wide Scheduler**: Manage all drivers' schedules in a single calendar view
- **Driver Schedule Management**: Create, edit, and manage driver shifts
- **HOS Compliance**: Real-time validation against FMCSA regulations
- **Calendar View**: Visual schedule management with drag-and-drop
- **Resource Timeline**: View multiple drivers' schedules simultaneously
- **Individual Driver Schedules**: Dedicated schedule view on driver detail pages
- **Availability Management**: Track driver availability and time-off requests
- **Conflict Detection**: Automatic detection of scheduling conflicts
- **Dual View Modes**: Toggle between order scheduling and driver scheduling

## Components

### Operations/Scheduler (Fleet-Wide View)

The main scheduler view at `operations/scheduler` now supports both order scheduling and driver scheduling.

**Location**: `addon/controllers/operations/scheduler/index.js`

**Features**:
- **View Mode Toggle**: Switch between "Orders" and "Driver Schedules" modes
- **Resource Timeline**: In driver mode, shows all drivers as resources with their shifts
- **Drag-and-Drop**: Drag shifts between drivers or reschedule by dragging
- **Add Shift**: Quick action button to create new driver shifts
- **Real-Time Updates**: Calendar updates automatically when shifts are modified
- **Status Colors**: Visual indicators for shift status (pending, confirmed, etc.)

**View Modes**:
1. **Orders Mode** (default):
   - Month calendar view
   - Drag unscheduled orders to calendar
   - Manage order scheduling

2. **Driver Schedules Mode**:
   - Resource timeline view (week view with drivers as resources)
   - View all drivers' shifts simultaneously
   - Drag shifts between drivers
   - Reschedule shifts by dragging
   - Click shifts to view/edit details

**Usage**:
Navigate to Operations → Scheduler, then toggle between "Orders" and "Driver Schedules" using the header buttons.

### Driver::Schedule

Displays and manages a driver's schedule from their detail page.

**Location**: `addon/components/driver/schedule.js`

**Features**:
- HOS compliance dashboard with visual indicators
- Weekly calendar view of driver shifts
- Upcoming shifts list (next 5 shifts)
- Availability and time-off management
- Quick actions for adding shifts and requesting time off

**Usage**:
```handlebars
<Driver::Schedule @driver={{@model}} />
```

**Integration**: Add a "Schedule" tab to the driver detail page.

## Backend Components

### HOSConstraint

Validates schedule items against FMCSA Hours of Service regulations.

**Location**: `server/src/Constraints/HOSConstraint.php`

**Regulations Enforced**:
1. **11-Hour Driving Limit**: Maximum 11 hours driving after 10 consecutive hours off duty
2. **14-Hour Duty Window**: Cannot drive beyond 14th hour after coming on duty
3. **60/70-Hour Weekly Limit**: Cannot drive after 60/70 hours in 7/8 consecutive days
4. **30-Minute Break**: Required after 8 cumulative hours of driving

**Violation Severity**:
- `critical`: Immediate compliance issue, shift cannot be scheduled
- `warning`: Approaching limit, requires attention

**Registration**:
```php
// In FleetOps ServiceProvider boot() method
$constraintService = app(\Fleetbase\Services\Scheduling\ConstraintService::class);
$constraintService->register('driver', \Fleetbase\FleetOps\Constraints\HOSConstraint::class);
```

## HOS Compliance Dashboard

The HOS dashboard displays:
- **Daily Driving Hours**: X/11 hours with circular progress indicator
- **Weekly Hours**: X/70 hours with circular progress indicator
- **Compliance Status**: Badge indicating compliance level
  - Green: Compliant
  - Yellow: Approaching Limit
  - Red: At Limit

## API Endpoints

FleetOps extends the core scheduling endpoints with driver-specific functionality:

- `GET /drivers/{id}/hos-status` - Get HOS compliance status for a driver
- `GET /drivers/{id}/schedule` - Get schedule items for a driver
- `POST /drivers/{id}/schedule` - Create a new shift for a driver
- `PUT /schedule-items/{id}` - Update a shift (with HOS validation)
- `DELETE /schedule-items/{id}` - Delete a shift

## Workflow

### Creating a Driver Shift

1. User clicks "Add Shift" button
2. Modal opens with shift form
3. User selects date, time, vehicle, and other details
4. System validates against HOS constraints
5. If violations found, display warnings/errors
6. If valid, create schedule item
7. Update driver schedule view and HOS dashboard

### HOS Validation

1. When a schedule item is created/updated
2. `HOSConstraint::validate()` is called
3. Checks all four HOS regulations
4. Returns violations array if any
5. Frontend displays violations to user
6. User can adjust shift or override (with proper permissions)

## Integration with Core Scheduling

FleetOps uses the core scheduling module with driver-specific customizations:

**Core Components Used**:
- `Schedule` model (subject_type: 'fleet', subject_uuid: fleet.id)
- `ScheduleItem` model (assignee_type: 'driver', assignee_uuid: driver.id)
- `ScheduleAvailability` model (subject_type: 'driver')
- `ScheduleConstraint` model (subject_type: 'driver')

**FleetOps Extensions**:
- `HOSConstraint` for compliance validation
- `Driver::Schedule` component for driver-specific UI
- HOS status API endpoint
- Driver-specific scheduling logic

## Future Enhancements

- **Automatic Schedule Generation**: AI-powered schedule optimization
- **ELD Integration**: Sync with Electronic Logging Devices
- **Predictive HOS**: Forecast HOS availability for future dates
- **Mobile App**: Driver-facing mobile app for schedule viewing
- **Notifications**: Real-time alerts for schedule changes and HOS warnings
- **Reporting**: HOS compliance reports and analytics

## Testing

### HOS Constraint Tests

Test cases should cover:
- 11-hour driving limit enforcement
- 14-hour duty window enforcement
- 60/70-hour weekly limit enforcement
- 30-minute break requirement
- Edge cases (consecutive shifts, split shifts, etc.)

### Integration Tests

- Create driver shift via API
- Validate HOS constraints are enforced
- Update shift and verify re-validation
- Delete shift and verify HOS recalculation
- Test driver schedule view rendering

## Compliance Notes

This implementation follows FMCSA Hours of Service regulations as of 2025. Regulations may vary by:
- Jurisdiction (US Federal, state-specific, Canada, etc.)
- Vehicle type (property-carrying vs. passenger-carrying)
- Industry (short-haul vs. long-haul)

**Important**: This is a software implementation and should not be the sole method of HOS compliance. Proper driver training, ELD integration, and regular audits are essential for full compliance.
