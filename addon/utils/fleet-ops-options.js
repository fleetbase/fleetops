import toPowerSelectGroups from '@fleetbase/ember-ui/utils/to-power-select-groups';

export const driverTypes = [
    { label: 'Full-time', value: 'full_time', description: 'Permanent employee driver' },
    { label: 'Part-time', value: 'part_time', description: 'Part-time or contract driver' },
    { label: 'Contractor', value: 'contractor', description: 'Independent contractor driver' },
    { label: 'Owner-Operator', value: 'owner_operator', description: 'Driver owns and operates own vehicle' },
    { label: 'Temporary', value: 'temporary', description: 'Short-term or seasonal driver' },
    { label: 'Apprentice', value: 'apprentice', description: 'Trainee or under-supervision driver' },
    { label: 'Relief / On-call', value: 'relief', description: 'Covers shifts and peak demand as needed' },
    { label: 'Hazmat Certified', value: 'hazmat', description: 'Qualified to carry hazardous materials' },
    { label: 'Refrigerated Cargo Specialist', value: 'reefer_specialist', description: 'Experienced with temperature-controlled cargo' },
    { label: 'Oversize / Heavy Haul', value: 'heavy_haul', description: 'Certified for oversize/overweight loads' },
    { label: 'Courier / Last-mile', value: 'last_mile', description: 'Small parcel/urban delivery specialist' },
];

export const driverStatuses = [
    { label: 'Active', value: 'active', description: 'Driver is available for assignments' },
    { label: 'Inactive', value: 'inactive', description: 'Driver is not currently active' },
    { label: 'On Duty', value: 'on_duty', description: 'Driver is working and available' },
    { label: 'Off Duty', value: 'off_duty', description: 'Driver is not working' },
    { label: 'On Break', value: 'on_break', description: 'Driver is on a scheduled break' },
    { label: 'Suspended', value: 'suspended', description: 'Driver is temporarily suspended' },
    { label: 'Terminated', value: 'terminated', description: 'Driver no longer works with company' },
    { label: 'Pre-shift', value: 'pre_shift', description: 'Clocked in, prepping for shift' },
    { label: 'Training', value: 'training', description: 'In training, not available for jobs' },
    { label: 'Onboarding', value: 'onboarding', description: 'Documents/induction in progress' },
    { label: 'On Route', value: 'on_route', description: 'Actively driving a planned route' },
    { label: 'Delayed', value: 'delayed', description: 'Experiencing delay (traffic, weather, etc.)' },
    { label: 'Accident Hold', value: 'accident_hold', description: 'Temporarily paused due to incident' },
    { label: 'On Leave', value: 'on_leave', description: 'Approved leave period' },
    { label: 'No-show', value: 'no_show', description: 'Missed scheduled shift' },
    { label: 'Pending Verification', value: 'pending_verification', description: 'Waiting on document/compliance checks' },
];

export const vehicleTypes = [
    { label: 'Truck', value: 'truck', description: 'Standard freight truck' },
    { label: 'Van', value: 'van', description: 'Cargo or delivery van' },
    { label: 'Trailer', value: 'trailer', description: 'Detachable cargo trailer' },
    { label: 'Motorbike', value: 'motorbike', description: 'Two-wheeled delivery bike' },
    { label: 'Car', value: 'car', description: 'Passenger car or small vehicle' },
    { label: 'Refrigerated Truck', value: 'reefer', description: 'Temperature-controlled transport' },
    { label: 'Container Truck', value: 'container_truck', description: 'Truck for containerized cargo' },
    { label: 'Tanker', value: 'tanker', description: 'Truck for liquid/gas transport' },
    { label: 'Box Truck', value: 'box_truck', description: 'Enclosed box body truck' },
    { label: 'Flatbed', value: 'flatbed', description: 'Open platform for oversized cargo' },
    { label: 'Pickup', value: 'pickup', description: 'Light-duty utility pickup' },
    { label: 'Bus / Minibus', value: 'bus', description: 'Passenger transport' },
    { label: 'Forklift', value: 'forklift', description: 'Material handling equipment' },
    { label: 'EV (Electric Vehicle)', value: 'ev', description: 'Battery electric vehicle' },
    { label: 'Hybrid', value: 'hybrid', description: 'Hybrid electric vehicle' },
    { label: 'Charging Trailer', value: 'charging_trailer', description: 'Mobile charging or auxiliary power unit' },
];

export const vehicleStatuses = [
    { label: 'Available', value: 'available', description: 'Vehicle is ready for use' },
    { label: 'In Use', value: 'in_use', description: 'Vehicle is assigned to a driver/order' },
    { label: 'Maintenance', value: 'maintenance', description: 'Vehicle is under maintenance or repair' },
    { label: 'Out of Service', value: 'out_of_service', description: 'Vehicle is not operational' },
    { label: 'Reserved', value: 'reserved', description: 'Vehicle is reserved for a future assignment' },
    { label: 'Retired', value: 'retired', description: 'Vehicle is decommissioned' },
    { label: 'Staging', value: 'staging', description: 'Prepped and queued for dispatch' },
    { label: 'On Route', value: 'on_route', description: 'Actively executing a route' },
    { label: 'Idle', value: 'idle', description: 'Powered on but not moving' },
    { label: 'Cleaning', value: 'cleaning', description: 'Under wash/detailing' },
    { label: 'Awaiting Parts', value: 'awaiting_parts', description: 'Maintenance waiting for spares' },
    { label: 'Inspection Due', value: 'inspection_due', description: 'Compliance inspection required' },
    { label: 'Inspection Failed', value: 'inspection_failed', description: 'Failed inspection—cannot dispatch' },
    { label: 'Accident / Damage', value: 'accident', description: 'Unavailable due to incident' },
    { label: 'Compliance Hold', value: 'compliance_hold', description: 'Blocked due to expired docs/permits' },
    { label: 'Stolen', value: 'stolen', description: 'Reported stolen—do not dispatch' },
];

export const vendorTypes = [
    { label: 'Vendor', value: 'vendor', description: 'General vendor type for uncategorized services' },
    { label: 'Integrated Vendor', value: 'integrated_vendor', description: 'Vendor with native API integration into Fleetbase' },
    { label: 'Fuel Supplier', value: 'fuel_supplier', description: 'Provides fuel for vehicles' },
    { label: 'Maintenance Provider', value: 'maintenance_provider', description: 'Performs vehicle repairs/maintenance' },
    { label: 'Parts Supplier', value: 'parts_supplier', description: 'Supplies vehicle parts' },
    { label: 'Technology Provider', value: 'tech_provider', description: 'Provides software or hardware services' },
    { label: 'Logistics Partner', value: 'logistics_partner', description: '3PL or logistics subcontractor' },
    { label: 'Insurance Provider', value: 'insurance_provider', description: 'Provides insurance services' },
    { label: 'Towing Service', value: 'towing_service', description: 'Roadside assistance, towing, and recovery' },
    { label: 'Leasing Company', value: 'leasing_company', description: 'Provides rental or leased vehicles and equipment' },
    { label: 'Driver Staffing Agency', value: 'driver_staffing', description: 'Provides contract or temporary drivers' },
    { label: 'Customs Broker', value: 'customs_broker', description: 'Handles border clearance and regulatory compliance' },
    { label: 'Telematics Provider', value: 'telematics_provider', description: 'Supplies GPS, ELD, and IoT telematics services' },
    { label: 'Freight Forwarder', value: 'freight_forwarder', description: 'Manages international and multi-modal shipments' },
    { label: 'Compliance / Audit', value: 'compliance_audit', description: 'Regulatory audits, safety, training' },
    { label: 'Wash & Detailing', value: 'wash_detailing', description: 'Cleaning and sanitization services' },
    { label: 'Tyre / Tire Service', value: 'tire_service', description: 'Tyre sales, repair, and fitting' },
    { label: 'Body & Paint Shop', value: 'body_paint', description: 'Accident repair and refinishing' },
    { label: 'Storage / Yard', value: 'yard_storage', description: 'Short/long-term vehicle storage' },
    { label: 'Charging Network', value: 'charging_network', description: 'EV charging infrastructure provider' },
];

export const vendorStatuses = [
    { label: 'Active', value: 'active', description: 'Vendor is active and available' },
    { label: 'Inactive', value: 'inactive', description: 'Vendor is inactive or on hold' },
    { label: 'Suspended', value: 'suspended', description: 'Vendor is temporarily suspended' },
    { label: 'Blacklisted', value: 'blacklisted', description: 'Vendor is banned from operations' },
    { label: 'Connected', value: 'connected', description: 'API connection healthy' },
    { label: 'Syncing', value: 'syncing', description: 'Data synchronization in progress' },
    { label: 'Integration Error', value: 'integration_error', description: 'API connection failing—needs attention' },
    { label: 'Under Review', value: 'under_review', description: 'Being evaluated for approval' },
    { label: 'Preferred', value: 'preferred', description: 'Preferred supplier with negotiated terms' },
    { label: 'Prequalified', value: 'prequalified', description: 'Meets minimum standards—conditional' },
    { label: 'Terminated', value: 'terminated', description: 'Relationship formally ended' },
];

export const contactTypes = [
    { label: 'Customer', value: 'customer', description: 'End customer contact' },
    { label: 'Vendor Contact', value: 'vendor_contact', description: 'Point of contact at vendor' },
    { label: 'Driver Contact', value: 'driver_contact', description: 'Driver or field contact' },
    { label: 'Fleet Manager', value: 'fleet_manager', description: 'Contact for managing fleets' },
    { label: 'Support', value: 'support', description: 'General support or helpdesk contact' },
    { label: 'Consignor', value: 'consignor', description: 'Sender of goods' },
    { label: 'Consignee', value: 'consignee', description: 'Receiver of goods' },
    { label: 'Accounts Payable', value: 'accounts_payable', description: 'Billing and payments contact' },
    { label: 'Procurement', value: 'procurement', description: 'Purchasing contact' },
    { label: 'Site Security', value: 'site_security', description: 'Gatehouse/security desk' },
    { label: 'Dock Manager', value: 'dock_manager', description: 'Loading dock operations contact' },
    { label: 'Dispatcher', value: 'dispatcher', description: 'Scheduling and operations contact' },
];

export const contactStatuses = [
    { label: 'Active', value: 'active', description: 'Contact is valid and in use' },
    { label: 'Inactive', value: 'inactive', description: 'Contact is no longer valid' },
    { label: 'Blocked', value: 'blocked', description: 'Contact is blocked from use' },
    { label: 'Primary', value: 'primary', description: 'Primary contact for an account/place' },
    { label: 'Verified', value: 'verified', description: 'Email/phone verified' },
    { label: 'Unverified', value: 'unverified', description: 'Contact details not verified' },
    { label: 'Do Not Contact', value: 'do_not_contact', description: 'Respect DNC for this contact' },
    { label: 'Archived', value: 'archived', description: 'Kept for record; not actively used' },
];

export const fleetTypes = [
    { label: 'Regional Fleet', value: 'regional', description: 'Fleet covering a specific region' },
    { label: 'Long Haul Fleet', value: 'long_haul', description: 'Fleet for cross-country or international routes' },
    { label: 'Urban Fleet', value: 'urban', description: 'Fleet for city/local deliveries' },
    { label: 'Specialized Fleet', value: 'specialized', description: 'Fleet for special cargo like hazmat or reefer' },
    { label: 'Last-mile Fleet', value: 'last_mile', description: 'Dense urban, short-drop operations' },
    { label: 'Linehaul / Middle-mile', value: 'linehaul', description: 'Terminal-to-terminal trunk routes' },
    { label: 'Service Fleet', value: 'service', description: 'Field service/technician vehicles' },
    { label: 'Rental Pool', value: 'rental_pool', description: 'Pool of shared/rental vehicles' },
    { label: 'Electric Fleet', value: 'electric', description: 'EV-focused operations' },
    { label: 'Motorcycle Courier', value: 'moto_courier', description: 'Two-wheeler delivery fleet' },
];

export const fleetStatuses = [
    { label: 'Active', value: 'active', description: 'Fleet is active and operational' },
    { label: 'Inactive', value: 'inactive', description: 'Fleet is inactive or dissolved' },
    { label: 'On Hold', value: 'on_hold', description: 'Fleet operations are paused' },
    { label: 'Scaling', value: 'scaling', description: 'Actively adding vehicles/drivers' },
    { label: 'Downsizing', value: 'downsizing', description: 'Reducing footprint' },
    { label: 'Seasonal', value: 'seasonal', description: 'Operational during specific seasons' },
    { label: 'Pilot', value: 'pilot', description: 'Trial/limited-scope operations' },
    { label: 'Decommissioning', value: 'decommissioning', description: 'Winding down; reassigning assets' },
];

export const fuelReportTypes = [
    { label: 'Refueling', value: 'refueling', description: 'Standard refueling log' },
    { label: 'Top-up', value: 'top_up', description: 'Partial refuel' },
    { label: 'Full Tank', value: 'full_tank', description: 'Complete refuel to full capacity' },
    { label: 'Adjustment', value: 'adjustment', description: 'Correction or manual adjustment' },
    { label: 'Card Transaction', value: 'card_txn', description: 'Imported from fuel card/processor' },
    { label: 'Manual Entry', value: 'manual_entry', description: 'Entered by user/driver' },
    { label: 'Bulk Refuel', value: 'bulk_refuel', description: 'Yard or tank bulk fueling event' },
    { label: 'DEF / AdBlue', value: 'def', description: 'Diesel Exhaust Fluid purchase' },
    { label: 'Transfer', value: 'transfer', description: 'Fuel moved between assets/tanks' },
    { label: 'Refund / Reversal', value: 'refund', description: 'Refunded or reversed transaction' },
];

export const fuelReportStatuses = [
    { label: 'Recorded', value: 'recorded', description: 'Fuel report has been logged' },
    { label: 'Verified', value: 'verified', description: 'Fuel report verified by manager' },
    { label: 'Disputed', value: 'disputed', description: 'Fuel report is under review' },
    { label: 'Rejected', value: 'rejected', description: 'Fuel report was rejected' },
    { label: 'Pending Review', value: 'pending', description: 'Awaiting manager verification' },
    { label: 'Matched', value: 'matched', description: 'Matched to trip/vehicle automatically' },
    { label: 'Unmatched', value: 'unmatched', description: 'No matching trip/vehicle found' },
    { label: 'Flagged Anomaly', value: 'flagged', description: 'Outlier (volume, price, location, time)' },
    { label: 'Reimbursed', value: 'reimbursed', description: 'Driver expense reimbursed' },
];

export const issueStatuses = [
    { label: 'Open', value: 'open', description: 'Issue is open and unresolved' },
    { label: 'Pending', value: 'pending', description: 'Issue is logged but work has not yet started' },
    { label: 'Triage', value: 'triage', description: 'Initial assessment and classification' },
    { label: 'Backlogged', value: 'backlogged', description: 'Issue is prioritized but not currently scheduled' },
    { label: 'In Progress', value: 'in_progress', description: 'Issue is being worked on' },
    { label: 'Requires Update', value: 'requires_update', description: 'Additional information is needed to proceed' },
    { label: 'In Review', value: 'in_review', description: 'Issue is under review or QA' },
    { label: 'Re-opened', value: 're_opened', description: 'Issue was closed/resolved but has been reopened' },
    { label: 'Awaiting Parts', value: 'awaiting_parts', description: 'Blocked pending required parts' },
    { label: 'Awaiting Vendor', value: 'awaiting_vendor', description: 'Waiting on third-party action' },
    { label: 'Monitoring', value: 'monitoring', description: 'Issue resolved but under observation' },
    { label: 'Escalated', value: 'escalated', description: 'Issue escalated for higher review' },
    { label: 'Duplicate', value: 'duplicate', description: 'Merged into an existing issue' },
    { label: 'Pending Review', value: 'pending_review', description: 'Awaiting manager or stakeholder review' },
    { label: 'Resolved', value: 'resolved', description: 'Issue has been resolved' },
    { label: 'Completed', value: 'completed', description: 'Work is fully completed and verified' },
    { label: 'Closed', value: 'closed', description: 'Issue is closed and no further action is needed' },
    { label: "Won't Fix", value: 'wont_fix', description: 'Accepted risk—no action planned' },
    { label: 'Canceled', value: 'canceled', description: 'Issue was canceled before completion' },
];

export const issueTypes = [
    { label: 'Vehicle', value: 'vehicle', description: 'Vehicle-related issues (mechanical, electrical, damage, accidents, etc.)' },
    { label: 'Driver', value: 'driver', description: 'Driver-related issues (behavior, training, time management, documentation, safety)' },
    { label: 'Route', value: 'route', description: 'Routing and navigation issues (deviation, blockages, weather, efficiency)' },
    { label: 'Payload / Cargo', value: 'payload-cargo', description: 'Cargo issues (damage, loss, documentation, temperature control)' },
    { label: 'Software / Technical', value: 'software-technical', description: 'System/app/device issues (bugs, outages, integrations, security)' },
    { label: 'Operational', value: 'operational', description: 'Process, resource, vendor, or cost issues; compliance & paperwork' },
    { label: 'Customer', value: 'customer', description: 'Customer service, billing, communications, and order accuracy' },
    { label: 'Security', value: 'security', description: 'Unauthorized access, theft, vandalism, and data/security incidents' },
    { label: 'Environmental / Sustainability', value: 'environmental-sustainability', description: 'Environmental hazards, emissions, waste, and green initiatives' },
];

export const issueCategories = [
    // Vehicle
    { label: 'Mechanical Problems', value: 'mechanical_problems', description: 'Issues related to vehicle mechanical performance or breakdowns.', group: 'vehicle' },
    { label: 'Electrical Faults', value: 'electrical_faults', description: 'Electrical/battery faults impacting operation.', group: 'vehicle' },
    { label: 'Accidents & Collisions', value: 'accidents_collisions', description: 'Any accident/incident involving the vehicle.', group: 'vehicle' },
    { label: 'Cosmetic Damages', value: 'cosmetic_damages', description: 'Non-critical bodywork or appearance issues.', group: 'vehicle' },
    { label: 'Tire Issues', value: 'tire_issues', description: 'Wear, punctures, blowouts, alignment issues.', group: 'vehicle' },
    { label: 'Electronics & Instruments', value: 'electronics_instruments', description: 'Dash, sensors, telematics hardware, or instrument faults.', group: 'vehicle' },
    { label: 'Maintenance Alerts', value: 'maintenance_alerts', description: 'Scheduled/overdue maintenance notifications.', group: 'vehicle' },
    { label: 'Fuel Efficiency Issues', value: 'fuel_efficiency', description: 'Abnormal consumption vs. baseline.', group: 'vehicle' },
    { label: 'Fuel Shortage', value: 'fuel_shortage', description: 'Unexpected low fuel resulting in disruption.', group: 'vehicle' },

    // Driver
    { label: 'Behavior Concerns', value: 'behavior_concerns', description: 'Conduct, professionalism, or policy adherence.', group: 'driver' },
    { label: 'Documentation', value: 'driver_documentation', description: 'Licenses, permits, or expired credentials.', group: 'driver' },
    { label: 'Time Management', value: 'time_management', description: 'Delays, missed windows, or poor scheduling adherence.', group: 'driver' },
    { label: 'Communication', value: 'driver_communication', description: 'Driver↔dispatch/customer communication gaps.', group: 'driver' },
    { label: 'Training Needs', value: 'training_needs', description: 'Upskilling or re-training required.', group: 'driver' },
    { label: 'Health & Safety Violations', value: 'health_safety', description: 'H&S non-compliance or incidents.', group: 'driver' },

    // Route
    { label: 'Route Deviation', value: 'route_deviation', description: 'Departed from planned route.', group: 'route' },
    { label: 'Inefficient Routes', value: 'inefficient_routes', description: 'Unnecessary time/cost/mileage.', group: 'route' },
    { label: 'Safety Concerns', value: 'route_safety', description: 'Hazards along the route.', group: 'route' },
    { label: 'Blocked Routes', value: 'blocked_routes', description: 'Road closures, restrictions, or access denied.', group: 'route' },
    { label: 'Unfavorable Weather Conditions', value: 'unfavorable_weather', description: 'Weather delays or hazards.', group: 'route' },
    { label: 'Environmental Considerations', value: 'environmental_considerations', description: 'Environmental restrictions along route.', group: 'route' },

    // Payload / Cargo
    { label: 'Cargo Damage / Loss', value: 'cargo_damage', description: 'Goods damaged, spoiled, or missing.', group: 'payload-cargo' },
    { label: 'Damaged Goods', value: 'damaged_goods', description: 'Goods received/delivered damaged.', group: 'payload-cargo' },
    { label: 'Misplaced Goods', value: 'misplaced_goods', description: 'Misrouted or lost items.', group: 'payload-cargo' },
    { label: 'Documentation Issues', value: 'cargo_documentation', description: 'Incorrect/missing cargo docs.', group: 'payload-cargo' },
    { label: 'Temperature-Sensitive Goods', value: 'temperature_sensitive_goods', description: 'Cold-chain or thermal control failures.', group: 'payload-cargo' },
    { label: 'Incorrect Cargo Loading', value: 'incorrect_cargo_loading', description: 'Poor load plan or unsafe stacking.', group: 'payload-cargo' },

    // Software / Technical
    { label: 'Bugs', value: 'bugs', description: 'Defects impacting workflow.', group: 'software-technical' },
    { label: 'System Outage', value: 'system_outage', description: 'App/platform downtime or connectivity loss.', group: 'software-technical' },
    { label: 'Integration Failures', value: 'integration_failures', description: '3rd-party/API sync or contract errors.', group: 'software-technical' },
    { label: 'Performance', value: 'performance', description: 'Slow or degraded system behavior.', group: 'software-technical' },
    { label: 'Feature Requests', value: 'feature_requests', description: 'Requested improvements or capabilities.', group: 'software-technical' },
    { label: 'Security Vulnerabilities', value: 'security_vulnerabilities', description: 'Potential exploit or insecure config.', group: 'software-technical' },

    // Operational
    { label: 'Compliance', value: 'compliance', description: 'Regulatory or policy non-compliance.', group: 'operational' },
    { label: 'Paperwork & Permits', value: 'paperwork_permits', description: 'Incorrect/expired documents or filings.', group: 'operational' },
    { label: 'Resource Allocation', value: 'resource_allocation', description: 'Insufficient/incorrect staffing or assets.', group: 'operational' },
    { label: 'Cost Overruns', value: 'cost_overruns', description: 'Budget/expense anomalies.', group: 'operational' },
    { label: 'Communication', value: 'operational_communication', description: 'Internal comms failures.', group: 'operational' },
    { label: 'Vendor Management Issues', value: 'vendor_management', description: '3rd-party coordination or SLA gaps.', group: 'operational' },

    // Customer
    { label: 'Customer Complaint', value: 'customer_complaint', description: 'Reported dissatisfaction or incident.', group: 'customer' },
    { label: 'Service Quality', value: 'service_quality', description: 'Service below expectation.', group: 'customer' },
    { label: 'Billing Discrepancies', value: 'billing_discrepancies', description: 'Invoice errors or disputes.', group: 'customer' },
    { label: 'Communication Breakdown', value: 'customer_communication', description: 'Customer comms issues.', group: 'customer' },
    { label: 'Order Errors', value: 'order_errors', description: 'Wrong item/quantity/address/etc.', group: 'customer' },

    // Security
    { label: 'Unauthorized Access', value: 'unauthorized_access', description: 'Intrusion or improper access.', group: 'security' },
    { label: 'Theft / Vandalism', value: 'theft', description: 'Stolen assets or malicious damage.', group: 'security' },
    { label: 'Data Concerns', value: 'data_concerns', description: 'Potential or actual data breach.', group: 'security' },
    { label: 'Physical Security', value: 'physical_security', description: 'Facility or cargo security weaknesses.', group: 'security' },
    { label: 'Data Integrity Issues', value: 'data_integrity', description: 'Corruption or inconsistency of data.', group: 'security' },

    // Environmental / Sustainability
    { label: 'Fuel Consumption', value: 'fuel_consumption', description: 'Excessive or abnormal usage patterns.', group: 'environmental-sustainability' },
    { label: 'Carbon Footprint', value: 'carbon_footprint', description: 'Emissions or footprint tracking issues.', group: 'environmental-sustainability' },
    { label: 'Waste Management', value: 'waste_management', description: 'Handling and disposal of waste.', group: 'environmental-sustainability' },
    { label: 'Green Initiatives Opportunities', value: 'green_initiatives', description: 'Improvements to sustainability programs.', group: 'environmental-sustainability' },
];

export const issuePriorities = [
    // Severity levels
    { label: 'Low', value: 'low', description: 'Minor issue with little to no operational impact.', group: 'severity' },
    { label: 'Medium', value: 'medium', description: 'Moderate issue requiring attention but not urgent.', group: 'severity' },
    { label: 'High', value: 'high', description: 'Serious issue affecting operations that needs prompt resolution.', group: 'severity' },
    { label: 'Critical', value: 'critical', description: 'Severe issue causing major disruption; requires immediate resolution.', group: 'severity' },

    // Planned / Informational priorities
    { label: 'Scheduled Maintenance', value: 'scheduled_maintenance', description: 'Planned maintenance tasks or service checks.', group: 'planned' },
    { label: 'Operational Suggestion', value: 'operational_suggestion', description: 'Improvement idea or recommendation, not an urgent issue.', group: 'planned' },
];

export const placeTypes = [
    { label: 'Warehouse', value: 'warehouse', description: 'Storage or distribution warehouse' },
    { label: 'Depot', value: 'depot', description: 'Operational depot or hub' },
    { label: 'Fuel Station', value: 'fuel_station', description: 'Refueling station' },
    { label: 'Checkpoint', value: 'checkpoint', description: 'Border or security checkpoint' },
    { label: 'Customer Location', value: 'customer_location', description: 'Customer delivery address' },
    { label: 'Vendor Facility', value: 'vendor_facility', description: 'Vendor-owned site' },
    { label: 'Port', value: 'port', description: 'Shipping or receiving port' },
    { label: 'Airport', value: 'airport', description: 'Air freight hub' },
    { label: 'Distribution Center', value: 'distribution_center', description: 'Regional DC / fulfillment' },
    { label: 'Cross-dock', value: 'cross_dock', description: 'Immediate transfer; minimal storage' },
    { label: 'Micro-hub', value: 'micro_hub', description: 'Urban mini-warehouse for last-mile' },
    { label: 'Rest Area / Layover', value: 'rest_area', description: 'Driver rest/layover location' },
    { label: 'Weigh Station', value: 'weigh_station', description: 'Regulatory weigh site' },
    { label: 'Toll Plaza', value: 'toll_plaza', description: 'Toll payment checkpoint' },
    { label: 'Service Center', value: 'service_center', description: 'Repair/maintenance facility' },
    { label: 'Charging Station', value: 'charging_station', description: 'EV charging location' },
];

export const placeStatuses = [
    { label: 'Active', value: 'active', description: 'Place is active and valid' },
    { label: 'Inactive', value: 'inactive', description: 'Place is no longer used' },
    { label: 'Restricted', value: 'restricted', description: 'Place has restricted access' },
    { label: 'Closed', value: 'closed', description: 'Place is permanently closed' },
    { label: 'New', value: 'new', description: 'Recently created—details may be incomplete' },
    { label: 'Temporarily Closed', value: 'temp_closed', description: 'Closed for a limited period' },
    { label: 'Under Construction', value: 'under_construction', description: 'Site works ongoing' },
    { label: 'Permit Required', value: 'permit_required', description: 'Entry requires permit/clearance' },
    { label: 'Approach with Caution', value: 'approach_caution', description: 'Known access difficulties or hazards' },
];

export const routeProfileOptions = [
    { label: 'Driving', value: 'driving', description: 'Optimized routes for motor vehicles.' },
    { label: 'Bicycle', value: 'bicycle', description: 'Optimized routes for bicycles with bike paths where available.' },
    { label: 'Walking', value: 'walking', description: 'Pedestrian-friendly routes suitable for walking.' },
];

export const podOptions = [
    { label: 'Scan', value: 'scan', description: 'Proof of delivery captured by scanning a QR code or barcode.' },
    { label: 'Signature', value: 'signature', description: 'Proof of delivery confirmed with recipient signature.' },
    { label: 'Photo', value: 'photo', description: 'Proof of delivery recorded with a photo of the delivered goods.' },
    { label: 'SMS', value: 'sms', description: 'Proof of delivery sent and confirmed via SMS verification.' },
];

export const serviceRateCalculationMethods = [
    { label: 'Per Meter', value: 'per_meter', description: 'Calculate cost based on the number of meters traveled.' },
    { label: 'Fixed Rate', value: 'fixed_meter', description: 'Charge a fixed rate per meter regardless of distance.' },
    { label: 'Parcel Rate', value: 'parcel', description: 'Charge a dynamic rate based on parcel/payload weight and dimensions.' },
    { label: 'Per Drop-off', value: 'per_drop', description: 'Cost applied per drop-off point.' },
    { label: 'Algorithm', value: 'algo', description: 'Cost calculated dynamically using algorithmic logic.' },
];

export const serviceRateCodCalculationMethods = [
    { label: 'Flat Fee', value: 'flat', description: 'A fixed fee applied to all COD transactions.' },
    { label: 'Percentage', value: 'percentage', description: 'Fee calculated as a percentage of the COD amount.' },
];

export const serviceRatePeakHourCalculationMethods = [
    { label: 'Flat Fee', value: 'flat', description: 'A fixed surcharge applied during peak hours.' },
    { label: 'Percentage', value: 'percentage', description: 'A surcharge calculated as a percentage of the base fare during peak hours.' },
];

export const distanceUnits = [
    { label: 'Meter', value: 'm', description: 'Distances measured in meters.' },
    { label: 'Kilometer', value: 'km', description: 'Distances measured in kilometers.' },
    { label: 'Feet', value: 'ft', description: 'Distances measured in feet.' },
    { label: 'Yard', value: 'yd', description: 'Distances measured in yards.' },
    { label: 'Mile', value: 'mi', description: 'Distances measured in miles.' },
];

export const longDistanceUnits = [
    { label: 'Kilometer', value: 'km', description: 'Distances measured in kilometers.' },
    { label: 'Mile', value: 'mi', description: 'Distances measured in miles.' },
];

export const dimensionUnits = [
    { label: 'Centimeters', value: 'cm', description: 'Measurements in centimeters.' },
    { label: 'Inches', value: 'in', description: 'Measurements in inches.' },
    { label: 'Feet', value: 'ft', description: 'Measurements in feet.' },
    { label: 'Millimeters', value: 'mm', description: 'Measurements in millimeters.' },
    { label: 'Meters', value: 'm', description: 'Measurements in meters.' },
    { label: 'Yards', value: 'yd', description: 'Measurements in yards.' },
];

export const weightUnits = [
    { label: 'Grams', value: 'g', description: 'Weight in grams.' },
    { label: 'Ounces', value: 'oz', description: 'Weight in ounces.' },
    { label: 'Pounds', value: 'lb', description: 'Weight in pounds.' },
    { label: 'Kilograms', value: 'kg', description: 'Weight in kilograms.' },
];

export const serviceAreaTypes = [
    { label: 'Neighborhood', value: 'neighborhood', description: 'A small, localized area within a city, typically residential or community-focused.' },
    { label: 'City', value: 'city', description: 'An urban area with its own municipal government and defined boundaries.' },
    { label: 'State', value: 'state', description: 'A major administrative division within a country, often with its own governing authority.' },
    { label: 'Province', value: 'province', description: 'A territorial division within a country, similar to a state, common in non-U.S. regions.' },
    { label: 'Region', value: 'region', description: 'A larger geographic area that may include multiple cities, states, or provinces.' },
    { label: 'Country', value: 'country', description: 'A sovereign nation with defined borders and central governance.' },
    { label: 'Continent', value: 'continent', description: 'A global landmass encompassing multiple countries (e.g., Asia, Europe, Africa).' },
];

export const telematicStatuses = [
    { value: 'initialized', label: 'Initialized', description: 'Provider entry has been created but not yet configured.' },
    { value: 'configured', label: 'Configured', description: 'Provider credentials and settings are valid but connection not yet tested.' },
    { value: 'connecting', label: 'Connecting', description: 'Attempting to establish a connection with provider API.' },
    { value: 'connected', label: 'Connected', description: 'Successfully authenticated and connected to provider API.' },
    { value: 'synchronizing', label: 'Synchronizing', description: 'Currently syncing data (devices, vehicles, positions, etc.) from provider.' },
    { value: 'active', label: 'Active', description: 'Integration is healthy and data syncs are occurring normally.' },
    { value: 'degraded', label: 'Degraded', description: 'Integration partially working; intermittent errors or missing data.' },
    { value: 'disconnected', label: 'Disconnected', description: 'Connection lost or failed authentication.' },
    { value: 'error', label: 'Error', description: 'Provider integration encountered a fatal issue.' },
    { value: 'disabled', label: 'Disabled', description: 'Manually disabled by the user.' },
    { value: 'archived', label: 'Archived', description: 'Deprecated or replaced integration, kept for record.' },
];

export const telematicHealthStates = [
    { value: 'healthy', label: 'Healthy', description: 'Integration tested and stable.' },
    { value: 'warning', label: 'Warning', description: 'Minor issues detected (e.g., slow response, nearing quota).' },
    { value: 'critical', label: 'Critical', description: 'Persistent failure or no data received in X hours.' },
];

export const deviceTypes = [
    { label: 'GPS Tracker', value: 'gps_tracker', description: 'Standalone GPS tracking device installed in vehicle or asset.' },
    { label: 'OBD-II Plug-in Device', value: 'obd2_plugin', description: 'Plugs into vehicle OBD-II port; reads diagnostics, engine data, and transmits telematics.' },
    { label: 'CAN Bus Module', value: 'can_bus_module', description: 'Hard-wired module connected to vehicle CAN bus for detailed vehicle subsystem data.' },
    { label: 'Dash Camera Telematics Unit', value: 'dashcam_unit', description: 'Camera + integrated telematics module capturing video and vehicle data.' },
    {
        label: 'Battery-Powered Asset Tracker',
        value: 'battery_asset_tracker',
        description: 'Self-powered wireless device for tracking non-powered assets (trailers, containers, equipment).',
    },
    { label: 'Smartphone App Device', value: 'smartphone_app_device', description: 'Mobile device running telematics/tracking app using phone sensors and connectivity.' },
    { label: 'Hard-wired Black Box Module', value: 'hardwired_black_box', description: 'Permanently installed telematics control unit wired to vehicle electrical system for fleet use.' },
    { label: 'Mobile Edge / IoT Gateway', value: 'mobile_edge_gateway', description: 'Multi-sensor/IoT gateway installed in vehicle or asset to aggregate sensor data and telematics.' },
    {
        label: 'Trailer/Container Telematic Sensor Node',
        value: 'trailer_container_node',
        description: 'Specialised telematics node for trailers, containers, intermodal assets, tracking location and status.',
    },
];

export const sensorTypes = [
    { label: 'GPS / GNSS Receiver', value: 'gps_gnss', description: 'Global positioning sensor providing latitude/longitude, speed and time information.' },
    { label: 'Accelerometer', value: 'accelerometer', description: 'Measures linear acceleration; used to detect harsh braking, rapid acceleration, and movement.' },
    { label: 'Gyroscope / IMU', value: 'gyroscope_imu', description: 'Inertial Measurement Unit estimating orientation, yaw/pitch/roll of vehicle or asset.' },
    { label: 'Fuel Level Sensor', value: 'fuel_level', description: 'Measures remaining fuel in tank; used for fuel monitoring and consumption tracking.' },
    { label: 'Tire Pressure Sensor (TPMS)', value: 'tire_pressure', description: 'Measures pressure (and often temperature) in tires for safety and maintenance.' },
    { label: 'Engine Diagnostic / OBD Sensor', value: 'engine_diagnostic', description: 'Reads engine fault codes, RPM, engine hours, and other diagnostics via OBD or CAN.' },
    { label: 'Temperature Sensor', value: 'temperature', description: 'Monitors ambient or equipment temperature; used for refrigerated cargo, battery health etc.' },
    { label: 'Humidity Sensor', value: 'humidity', description: 'Detects moisture/humidity levels in cargo or enclosed spaces for condition monitoring.' },
    { label: 'Door / Hatch Sensor', value: 'door_hatch', description: 'Monitors open/close status of doors, hatches or compartments for security/tracking.' },
    { label: 'Vibration / Shock Sensor', value: 'vibration_shock', description: 'Measures vibration or shock events; suitable for freight, containers or equipment stability monitoring.' },
    { label: 'Battery Voltage Sensor', value: 'battery_voltage', description: 'Monitors battery or electrical system voltage to detect low power or charging issues.' },
    { label: 'Geofence / Entry-Exit Event Sensor', value: 'geofence_event', description: 'Triggers when asset enters/exits defined geographic zone; used for location-based alerts.' },
    { label: 'Idle Time Sensor', value: 'idle_time', description: 'Tracks engine on with vehicle stationary; used to measure idling and optimize fuel/usage.' },
    { label: 'Driver Behavior Sensor', value: 'driver_behavior', description: 'Aggregates data (speed, braking, cornering) to score driving behavior and safety.' },
];

export const deviceStatuses = [
    { label: 'Inactive', value: 'inactive', description: 'Device record exists but has not yet been activated or assigned.' },
    { label: 'Active', value: 'active', description: 'Device is online and actively communicating with the platform.' },
    { label: 'Online', value: 'online', description: 'Device is currently connected and transmitting live data.' },
    { label: 'Offline', value: 'offline', description: 'Device is powered off or has not transmitted data within the expected interval.' },
    { label: 'Sleeping', value: 'sleeping', description: 'Device in low-power mode awaiting wake event or scheduled reporting interval.' },
    { label: 'Idle', value: 'idle', description: 'Device is powered and connected but not moving or transmitting new telemetry.' },
    { label: 'Maintenance', value: 'maintenance', description: 'Device is undergoing repair, firmware update, or service maintenance.' },
    { label: 'Degraded', value: 'degraded', description: 'Device is partially functional; intermittent connectivity or incomplete data.' },
    { label: 'Error', value: 'error', description: 'Device reported fault, hardware issue, or repeated communication errors.' },
    { label: 'Disconnected', value: 'disconnected', description: 'Device lost connection to network or telematic gateway.' },
    { label: 'Decommissioned', value: 'decommissioned', description: 'Device permanently retired or removed from active fleet use.' },
    { label: 'Disabled', value: 'disabled', description: 'Device manually disabled by user or admin; data not being accepted.' },
    { label: 'Pending Activation', value: 'pending_activation', description: 'Device provisioned but not yet verified or linked to telematic source.' },
    { label: 'Provisioning', value: 'provisioning', description: 'Device configuration or initialization is in progress.' },
];

export const sensorStatuses = [
    { label: 'Active', value: 'active', description: 'Sensor is online and transmitting readings as expected.' },
    { label: 'Inactive', value: 'inactive', description: 'Sensor exists but is not currently operational or reporting.' },
    { label: 'Online', value: 'online', description: 'Sensor is connected and sending live data via its parent device.' },
    { label: 'Offline', value: 'offline', description: 'Sensor has stopped sending data or is unreachable.' },
    { label: 'Calibrating', value: 'calibrating', description: 'Sensor undergoing calibration or self-test to ensure measurement accuracy.' },
    { label: 'Faulty', value: 'faulty', description: 'Sensor hardware failure or inconsistent readings detected.' },
    { label: 'Degraded', value: 'degraded', description: 'Sensor partially functional; data irregularities or unstable connection.' },
    { label: 'Maintenance', value: 'maintenance', description: 'Sensor temporarily disabled for maintenance, cleaning, or recalibration.' },
    { label: 'Error', value: 'error', description: 'Critical failure or unrecoverable error reported by sensor or gateway.' },
    { label: 'Disconnected', value: 'disconnected', description: 'Sensor communication link lost with device or telematic gateway.' },
    { label: 'Disabled', value: 'disabled', description: 'Sensor manually disabled; no data being collected.' },
    { label: 'Decommissioned', value: 'decommissioned', description: 'Sensor permanently retired, replaced, or removed from service.' },
];

export const measurementSystems = [
    { label: 'Metric System', value: 'metric', description: 'Internationally adopted system based on meters, liters, and kilograms; used by most countries.' },
    { label: 'Imperial System', value: 'imperial', description: 'Traditional British system using miles, gallons, and pounds; still widely used in the United States.' },
];

export const fuelVolumeUnits = [
    { label: 'Liters (L)', value: 'liters', description: 'Metric unit for measuring liquid fuel; standard in most of the world.' },
    { label: 'Gallons (US gal)', value: 'gallons_us', description: 'US customary gallon, equal to 3.785 liters; commonly used in the United States.' },
    { label: 'Gallons (Imperial gal)', value: 'gallons_imperial', description: 'UK Imperial gallon, equal to 4.546 liters; historically used in the UK and some Commonwealth countries.' },
];

export const fuelTypes = [
    { label: 'Petrol (Gasoline)', value: 'petrol', description: 'Traditional internal combustion engine fuel, widely available.' },
    { label: 'Diesel', value: 'diesel', description: 'Fuel for compression ignition engines, offering higher efficiency and torque.' },
    { label: 'Electric', value: 'electric', description: 'Battery-powered vehicles producing zero tailpipe emissions.' },
    { label: 'Hybrid', value: 'hybrid', description: 'Combines petrol/diesel engine with an electric motor for better fuel economy.' },
    { label: 'Liquefied Petroleum Gas (LPG)', value: 'lpg', description: 'Alternative fuel derived from propane or butane, lower emissions.' },
    { label: 'Compressed Natural Gas (CNG)', value: 'cng', description: 'Fuel stored at high pressure, cleaner than petrol or diesel.' },
];

export const vehicleUsageTypes = [
    { label: 'Commercial', value: 'commercial', description: 'Used for business operations such as deliveries, services, or company activities.' },
    { label: 'Personal', value: 'personal', description: 'Used by an individual for private, non-business purposes.' },
    { label: 'Mixed', value: 'mixed', description: 'Used for both business and personal purposes.' },
    { label: 'Rental', value: 'rental', description: 'Provided for short or long-term rental to third parties.' },
    { label: 'Fleet', value: 'fleet', description: 'Part of a company-managed group of vehicles or assets for organizational use.' },
    { label: 'Operational', value: 'operational', description: 'Vehicles actively in use for company operations or service delivery.' },
    { label: 'Standby', value: 'standby', description: 'Assets kept on standby for future or emergency use.' },
    { label: 'Under Maintenance', value: 'under_maintenance', description: 'Vehicles currently undergoing repair, inspection, or service.' },
    { label: 'Decommissioned', value: 'decommissioned', description: 'Retired assets no longer part of active operations.' },
    { label: 'In Transit', value: 'in_transit', description: 'Assets currently being transported between locations.' },
    { label: 'On Loan', value: 'on_loan', description: 'Assets temporarily loaned to another department or client.' },
];

export const vehicleOwnershipTypes = [
    { label: 'Company Owned', value: 'company_owned', description: 'Vehicles fully owned and managed by the company.' },
    { label: 'Leased', value: 'leased', description: 'Vehicles leased from a third-party vendor or lessor under contract.' },
    { label: 'Rented', value: 'rented', description: 'Short-term rental assets used for temporary fleet expansion or projects.' },
    { label: 'Financed', value: 'financed', description: 'Vehicles purchased through financing or loan agreements.' },
    { label: 'Vendor Supplied', value: 'vendor_supplied', description: 'Assets provided and maintained by external vendors.' },
    { label: 'Customer Owned', value: 'customer_owned', description: 'Assets owned by a customer but operated or tracked within the system.' },
];

export const vehicleBodyTypes = [
    { label: 'Sedan', value: 'sedan', description: 'Standard passenger car body with a fixed roof and trunk.' },
    { label: 'SUV', value: 'suv', description: 'Sport utility vehicle designed for passenger and cargo versatility.' },
    { label: 'Pickup Truck', value: 'pickup_truck', description: 'Truck with an open cargo area and enclosed cab.' },
    { label: 'Van', value: 'van', description: 'Multi-purpose vehicle for transporting passengers or goods.' },
    { label: 'Box Truck', value: 'box_truck', description: 'Enclosed cargo truck for logistics or delivery operations.' },
    { label: 'Flatbed', value: 'flatbed', description: 'Truck with a flat, open platform for hauling oversized loads.' },
    { label: 'Trailer', value: 'trailer', description: 'Unpowered vehicle towed for freight transport.' },
    { label: 'Bus', value: 'bus', description: 'Passenger transport vehicle with multiple seating rows.' },
];

export const vehicleBodySubTypes = [
    { label: 'Refrigerated Truck', value: 'refrigerated_truck', description: 'Temperature-controlled truck for transporting perishable goods.' },
    { label: 'Tanker', value: 'tanker', description: 'Vehicle designed for transporting liquids such as fuel or water.' },
    { label: 'Tipper Truck', value: 'tipper_truck', description: 'Truck equipped with a hydraulic bed for dumping bulk materials.' },
    { label: 'Car Carrier', value: 'car_carrier', description: 'Trailer or truck configured for vehicle transport.' },
    { label: 'Mini Van', value: 'mini_van', description: 'Compact van suitable for urban transport or light cargo.' },
    { label: 'Panel Van', value: 'panel_van', description: 'Enclosed van used for deliveries or small logistics operations.' },
    { label: 'Chassis Cab', value: 'chassis_cab', description: 'Truck base with customizable rear body configurations.' },
    { label: 'Electric Bus', value: 'electric_bus', description: 'Battery-powered bus used for sustainable public or private transport.' },
    { label: 'Motorbike', value: 'motorbike', description: 'Two-wheeled asset for rapid, lightweight transportation.' },
];

export const transmissionTypes = [
    { label: 'Manual', value: 'manual', description: 'Vehicles requiring manual gear shifting by the driver.' },
    { label: 'Automatic', value: 'automatic', description: 'Vehicles equipped with fully automatic transmission systems.' },
    { label: 'Semi-Automatic', value: 'semi_automatic', description: 'Combines manual control with automatic clutch operation.' },
    { label: 'CVT (Continuously Variable Transmission)', value: 'cvt', description: 'Uses a belt and pulley system for seamless gear ratio transitions.' },
    { label: 'Dual-Clutch', value: 'dual_clutch', description: 'High-performance transmission with two clutches for faster shifting.' },
    { label: 'Electric Drive', value: 'electric_drive', description: 'Single-speed transmission system used in electric vehicles (EVs).' },
];

export const odometerUnits = [...distanceUnits, { label: 'Hours', value: 'hours', description: 'Unit of time measurement, commonly used worldwide.' }];

// ─── Orchestrator: Driver & Vehicle Skills ────────────────────────────────────

/**
 * driverSkills
 * Comprehensive list of driver qualifications and certifications used by the
 * Orchestrator to match orders to appropriately skilled drivers.
 */
export const driverSkills = [
    // Licences & Certifications
    { label: 'Car Licence (B)', value: 'licence_b', description: 'Standard passenger car licence.', group: 'licence' },
    { label: 'Light Truck Licence (C)', value: 'licence_c', description: 'Licence for rigid trucks up to 8 tonnes GVM.', group: 'licence' },
    { label: 'Medium Rigid (MR)', value: 'licence_mr', description: 'Two-axle rigid vehicle up to 8 tonnes.', group: 'licence' },
    { label: 'Heavy Rigid (HR)', value: 'licence_hr', description: 'Three or more axle rigid vehicle.', group: 'licence' },
    { label: 'Heavy Combination (HC)', value: 'licence_hc', description: 'Prime mover + semi-trailer combination.', group: 'licence' },
    { label: 'Multi-Combination (MC)', value: 'licence_mc', description: 'B-double, road train, or multi-trailer combination.', group: 'licence' },
    { label: 'Motorcycle Licence (A)', value: 'licence_a', description: 'Motorcycle or scooter delivery licence.', group: 'licence' },
    { label: 'Forklift Licence', value: 'licence_forklift', description: 'Certified forklift operator.', group: 'licence' },
    { label: 'Dangerous Goods (DG)', value: 'cert_dg', description: 'Certified to transport dangerous goods under ADG/IMDG codes.', group: 'certification' },
    { label: 'Hazmat (US DOT)', value: 'cert_hazmat', description: 'US DOT hazardous materials endorsement.', group: 'certification' },
    { label: 'ADR Hazmat (EU)', value: 'cert_adr', description: 'European ADR dangerous goods driver certificate.', group: 'certification' },
    { label: 'Refrigerated Transport', value: 'cert_reefer', description: 'Trained in cold-chain and temperature-controlled transport.', group: 'certification' },
    { label: 'Oversize / Overweight', value: 'cert_oversize', description: 'Certified for oversize or overweight load operations.', group: 'certification' },
    { label: 'Passenger Transport', value: 'cert_passenger', description: 'Authorised to carry fare-paying passengers.', group: 'certification' },
    { label: 'First Aid', value: 'cert_first_aid', description: 'Current first aid certificate.', group: 'certification' },
    { label: 'Defensive Driving', value: 'cert_defensive_driving', description: 'Advanced defensive driving qualification.', group: 'certification' },
    // Operational Skills
    { label: 'Hand-truck / Trolley', value: 'skill_hand_truck', description: 'Proficient with hand-truck and trolley equipment.', group: 'operational' },
    { label: 'Tail-lift Operation', value: 'skill_tail_lift', description: 'Trained to operate hydraulic tail-lift.', group: 'operational' },
    { label: 'Crane / HIAB', value: 'skill_hiab', description: 'Certified to operate truck-mounted crane/HIAB.', group: 'operational' },
    { label: 'Pallet Jack', value: 'skill_pallet_jack', description: 'Manual or electric pallet jack operation.', group: 'operational' },
    { label: 'Pump-out / Tanker', value: 'skill_tanker', description: 'Trained in tanker loading, unloading, and pump operation.', group: 'operational' },
    { label: 'Livestock Handling', value: 'skill_livestock', description: 'Experienced with live animal transport and handling.', group: 'operational' },
    { label: 'Fragile / White Goods', value: 'skill_fragile', description: 'Trained in handling fragile or high-value goods.', group: 'operational' },
    { label: 'Pharmaceutical / Medical', value: 'skill_pharma', description: 'Experienced with pharmaceutical and medical supply chain requirements.', group: 'operational' },
    { label: 'Cash-in-Transit', value: 'skill_cit', description: 'Cleared and trained for cash-in-transit operations.', group: 'operational' },
    { label: 'Contactless / Unattended Delivery', value: 'skill_contactless', description: 'Proficient in unattended or safe-drop delivery procedures.', group: 'operational' },
    // Language & Customer Service
    { label: 'Customer-facing', value: 'skill_customer_facing', description: 'Experienced in direct customer interaction and service.', group: 'soft_skills' },
    { label: 'Multilingual', value: 'skill_multilingual', description: 'Fluent in more than one language.', group: 'soft_skills' },
    { label: 'Digital / App Proficient', value: 'skill_digital', description: 'Comfortable using mobile apps and digital POD tools.', group: 'soft_skills' },
];

/**
 * vehicleSkills
 * Equipment and capability flags for vehicles used by the Orchestrator to
 * match vehicles to orders requiring specific handling.
 */
export const vehicleSkills = [
    // Cargo Handling Equipment
    { label: 'Tail Lift', value: 'tail_lift', description: 'Hydraulic tail-lift for loading/unloading without a dock.', group: 'equipment' },
    { label: 'Crane / HIAB', value: 'hiab', description: 'Truck-mounted crane for self-loading.', group: 'equipment' },
    { label: 'Pallet Racking', value: 'pallet_racking', description: 'Internal pallet racking system.', group: 'equipment' },
    { label: 'Roller Bed / Conveyor Floor', value: 'roller_bed', description: 'Motorised roller floor for easy cargo movement.', group: 'equipment' },
    { label: 'Curtainsider', value: 'curtainsider', description: 'Curtain-sided trailer for easy side-loading.', group: 'equipment' },
    { label: 'Flatbed / Step-deck', value: 'flatbed', description: 'Open flatbed or step-deck for oversized loads.', group: 'equipment' },
    { label: 'Refrigerated (Reefer)', value: 'refrigerated', description: 'Active refrigeration unit for cold-chain cargo.', group: 'temperature' },
    { label: 'Chilled (2–8 °C)', value: 'chilled', description: 'Maintains chilled temperature range 2–8 °C.', group: 'temperature' },
    { label: 'Frozen (< −18 °C)', value: 'frozen', description: 'Maintains frozen temperature below −18 °C.', group: 'temperature' },
    { label: 'Ambient / Insulated', value: 'insulated', description: 'Insulated body without active cooling.', group: 'temperature' },
    { label: 'Heated / Warm', value: 'heated', description: 'Heated cargo space for temperature-sensitive goods in cold climates.', group: 'temperature' },
    // Compliance & Certification
    { label: 'Dangerous Goods Approved', value: 'dg_approved', description: 'Vehicle certified and placarded for dangerous goods.', group: 'compliance' },
    { label: 'ADR Certified (EU)', value: 'adr_certified', description: 'European ADR dangerous goods vehicle certification.', group: 'compliance' },
    { label: 'Oversize Permit', value: 'oversize_permit', description: 'Holds current oversize/overweight load permit.', group: 'compliance' },
    { label: 'Food Grade', value: 'food_grade', description: 'Cargo area meets food safety/hygiene standards.', group: 'compliance' },
    { label: 'Pharmaceutical Grade', value: 'pharma_grade', description: 'Meets GDP/pharmaceutical transport standards.', group: 'compliance' },
    { label: 'Cash-in-Transit Secure', value: 'cit_secure', description: 'Armoured or security-fitted for cash-in-transit.', group: 'compliance' },
    // Connectivity & Telematics
    { label: 'GPS / Live Tracking', value: 'gps_tracking', description: 'Fitted with live GPS tracking device.', group: 'telematics' },
    { label: 'Temperature Logger', value: 'temp_logger', description: 'Continuous temperature data logging.', group: 'telematics' },
    { label: 'Dash Camera', value: 'dash_cam', description: 'Forward-facing dash camera installed.', group: 'telematics' },
    { label: 'ELD / Tachograph', value: 'eld', description: 'Electronic logging device or digital tachograph fitted.', group: 'telematics' },
];

// ─── Orchestrator: Capacity & Dimension Options ───────────────────────────────

/**
 * capacityDimensions
 * The multi-dimensional capacity axes supported by the Orchestrator and VROOM.
 * Each dimension can be independently constrained on vehicles and required by orders.
 */
export const capacityDimensions = [
    { label: 'Weight (kg)', value: 'weight_kg', unit: 'kg', description: 'Gross cargo weight in kilograms.', group: 'weight' },
    { label: 'Weight (lb)', value: 'weight_lb', unit: 'lb', description: 'Gross cargo weight in pounds.', group: 'weight' },
    { label: 'Volume (m³)', value: 'volume_m3', unit: 'm³', description: 'Total cargo volume in cubic metres.', group: 'volume' },
    { label: 'Volume (ft³)', value: 'volume_ft3', unit: 'ft³', description: 'Total cargo volume in cubic feet.', group: 'volume' },
    { label: 'Pallets', value: 'pallets', unit: 'pallets', description: 'Number of standard EUR/AU pallets.', group: 'units' },
    { label: 'Parcels / Packages', value: 'parcels', unit: 'parcels', description: 'Count of individual parcels or packages.', group: 'units' },
    { label: 'Cartons / Cases', value: 'cartons', unit: 'cartons', description: 'Count of cartons or cases.', group: 'units' },
    { label: 'Linear Metres (LDM)', value: 'ldm', unit: 'LDM', description: 'Loading metres — floor space occupied by cargo.', group: 'length' },
    { label: 'Floor Area (m²)', value: 'floor_area_m2', unit: 'm²', description: 'Cargo floor area in square metres.', group: 'length' },
    { label: 'Seats / Passengers', value: 'seats', unit: 'seats', description: 'Passenger seat capacity (for passenger transport).', group: 'units' },
];

/**
 * vehicleCapacityProfiles
 * Common pre-defined capacity profiles for quick assignment to vehicles.
 */
export const vehicleCapacityProfiles = [
    { label: 'Motorbike / Scooter', value: 'moto', description: 'Up to 20 kg, 0.05 m³, 5 parcels.', weight_kg: 20, volume_m3: 0.05, parcels: 5 },
    { label: 'Small Car / Hatchback', value: 'small_car', description: 'Up to 150 kg, 0.3 m³, 20 parcels.', weight_kg: 150, volume_m3: 0.3, parcels: 20 },
    { label: 'Cargo Van (Small)', value: 'small_van', description: 'Up to 800 kg, 5 m³, 100 parcels.', weight_kg: 800, volume_m3: 5, parcels: 100 },
    { label: 'Cargo Van (Large)', value: 'large_van', description: 'Up to 1,500 kg, 10 m³, 200 parcels.', weight_kg: 1500, volume_m3: 10, parcels: 200 },
    { label: 'Light Truck (3.5t)', value: 'light_truck', description: 'Up to 3,500 kg, 20 m³, 6 pallets.', weight_kg: 3500, volume_m3: 20, pallets: 6 },
    { label: 'Medium Truck (7.5t)', value: 'medium_truck', description: 'Up to 7,500 kg, 40 m³, 14 pallets.', weight_kg: 7500, volume_m3: 40, pallets: 14 },
    { label: 'Heavy Truck (13.5t)', value: 'heavy_truck', description: 'Up to 13,500 kg, 60 m³, 22 pallets.', weight_kg: 13500, volume_m3: 60, pallets: 22 },
    { label: 'Semi-Trailer (26t)', value: 'semi_trailer', description: 'Up to 26,000 kg, 90 m³, 33 pallets.', weight_kg: 26000, volume_m3: 90, pallets: 33 },
    { label: 'B-Double (42.5t)', value: 'b_double', description: 'Up to 42,500 kg, 150 m³, 54 pallets.', weight_kg: 42500, volume_m3: 150, pallets: 54 },
    { label: 'Refrigerated Van', value: 'reefer_van', description: 'Up to 1,200 kg, 8 m³, cold-chain.', weight_kg: 1200, volume_m3: 8 },
    { label: 'Refrigerated Truck (10t)', value: 'reefer_truck', description: 'Up to 10,000 kg, 50 m³, cold-chain.', weight_kg: 10000, volume_m3: 50 },
];

// ─── Orchestrator: Order Priority Options ─────────────────────────────────────

/**
 * orderPriorityLevels
 * Routing priority levels for orders. Higher priority orders are served first
 * by the optimization engine when time windows conflict.
 */
export const orderPriorityLevels = [
    { label: 'Critical', value: 100, description: 'Must be served first — SLA breach imminent or emergency delivery.', color: 'red' },
    { label: 'High', value: 75, description: 'High-priority order; serve before standard orders.', color: 'orange' },
    { label: 'Standard', value: 50, description: 'Normal priority — default for all orders.', color: 'blue' },
    { label: 'Low', value: 25, description: 'Low-priority order; serve after higher-priority stops.', color: 'gray' },
    { label: 'Flexible', value: 10, description: 'No strict time requirement; fill remaining capacity.', color: 'green' },
];

// ─── Orchestrator: Time Window Presets ────────────────────────────────────────

/**
 * timeWindowPresets
 * Common delivery/pickup time window presets for quick assignment.
 * Times are stored as HH:MM strings; the backend converts to Unix timestamps.
 */
export const timeWindowPresets = [
    { label: 'Early Morning (06:00–09:00)', value: 'early_morning', start: '06:00', end: '09:00', description: 'Pre-business-hours delivery window.' },
    { label: 'Morning (08:00–12:00)', value: 'morning', start: '08:00', end: '12:00', description: 'Standard morning delivery window.' },
    { label: 'Midday (11:00–14:00)', value: 'midday', start: '11:00', end: '14:00', description: 'Lunchtime delivery window.' },
    { label: 'Afternoon (12:00–17:00)', value: 'afternoon', start: '12:00', end: '17:00', description: 'Standard afternoon delivery window.' },
    { label: 'Business Hours (09:00–17:00)', value: 'business_hours', start: '09:00', end: '17:00', description: 'Full business-hours window.' },
    { label: 'Evening (17:00–21:00)', value: 'evening', start: '17:00', end: '21:00', description: 'After-hours residential delivery.' },
    { label: 'Night (21:00–06:00)', value: 'night', start: '21:00', end: '06:00', description: 'Overnight or graveyard-shift delivery.' },
    { label: 'AM Only (Before 12:00)', value: 'am_only', start: '00:00', end: '12:00', description: 'Must be delivered before noon.' },
    { label: 'PM Only (After 12:00)', value: 'pm_only', start: '12:00', end: '23:59', description: 'Must be delivered after noon.' },
    { label: 'Anytime', value: 'anytime', start: '00:00', end: '23:59', description: 'No time restriction — deliver any time.' },
];

// ─── Orchestrator: Route Optimization Constraint Options ──────────────────────

/**
 * optimizationObjectives
 * The primary objective the optimization engine should minimise/maximise.
 */
export const optimizationObjectives = [
    { label: 'Minimize Total Distance', value: 'min_distance', description: 'Produce routes with the shortest total travel distance.', icon: 'route' },
    { label: 'Minimize Total Duration', value: 'min_duration', description: 'Produce routes with the shortest total travel time.', icon: 'clock' },
    { label: 'Minimize Vehicles Used', value: 'min_vehicles', description: 'Use as few vehicles as possible while serving all orders.', icon: 'truck' },
    { label: 'Balance Workload', value: 'balance_workload', description: 'Distribute stops evenly across all available drivers.', icon: 'scale' },
    { label: 'Maximize On-Time Delivery', value: 'max_on_time', description: 'Prioritise serving stops within their time windows.', icon: 'check-circle' },
    { label: 'Minimize Overtime', value: 'min_overtime', description: 'Keep driver working hours within contracted limits.', icon: 'user-clock' },
    { label: 'Minimize Fuel / Emissions', value: 'min_emissions', description: 'Optimize for lowest fuel consumption and CO₂ output.', icon: 'leaf' },
];

/**
 * routingConstraintOptions
 * Boolean constraint toggles exposed in the Orchestrator settings and run panel.
 */
export const routingConstraintOptions = [
    { label: 'Respect Time Windows', value: 'respect_time_windows', description: 'Enforce delivery/pickup time windows as hard constraints.' },
    { label: 'Respect Vehicle Capacity', value: 'respect_capacity', description: 'Do not exceed vehicle weight, volume, or unit capacity.' },
    { label: 'Respect Driver Skills', value: 'respect_skills', description: 'Only assign orders to drivers/vehicles with matching skills.' },
    { label: 'Respect Max Tasks per Route', value: 'respect_max_tasks', description: 'Do not exceed the maximum number of stops per vehicle.' },
    { label: 'Respect Max Travel Time', value: 'respect_max_travel_time', description: 'Do not exceed the maximum driving time per vehicle/driver.' },
    { label: 'Respect Max Distance', value: 'respect_max_distance', description: 'Do not exceed the maximum distance per vehicle/driver.' },
    { label: 'Allow Unassigned Orders', value: 'allow_unassigned', description: 'Permit orders to remain unassigned if no feasible vehicle exists.' },
    { label: 'Pickup Before Delivery', value: 'pickup_before_delivery', description: 'Enforce pickup-before-dropoff sequencing for P&D orders.' },
    { label: 'Return to Depot', value: 'return_to_depot', description: 'Require vehicles to return to their start depot after the last stop.' },
    { label: 'Avoid Highways', value: 'avoid_highways', description: 'Route via non-highway roads where possible.' },
    { label: 'Avoid Tolls', value: 'avoid_tolls', description: 'Route to avoid toll roads where possible.' },
    { label: 'Avoid Ferries', value: 'avoid_ferries', description: 'Route to avoid ferry crossings where possible.' },
];

/**
 * serviceTimePresets
 * Common service time (dwell time) presets for waypoints.
 * Represents the expected time in seconds to complete the task at a stop.
 */
export const serviceTimePresets = [
    { label: '2 minutes', value: 120, description: 'Quick drop — parcel left at door.' },
    { label: '5 minutes', value: 300, description: 'Standard residential drop-off.' },
    { label: '10 minutes', value: 600, description: 'Standard commercial delivery.' },
    { label: '15 minutes', value: 900, description: 'Delivery with signature/POD.' },
    { label: '20 minutes', value: 1200, description: 'Delivery with unloading assistance.' },
    { label: '30 minutes', value: 1800, description: 'Larger delivery or installation.' },
    { label: '45 minutes', value: 2700, description: 'Complex delivery or site inspection.' },
    { label: '1 hour', value: 3600, description: 'Full hour on-site service.' },
    { label: '2 hours', value: 7200, description: 'Extended on-site service or installation.' },
];

// ─── Orchestrator: Import Column Mapping Options ──────────────────────────────

/**
 * importColumnMappings
 * Canonical column names used by the Orchestrator order import flow.
 * Each entry defines the internal field name, a human-readable label, whether
 * it is required, and example values to guide column mapping.
 */
export const importColumnMappings = [
    // Order Identity
    { field: 'reference', label: 'Order Reference / ID', required: false, example: 'ORD-001', group: 'order' },
    { field: 'type', label: 'Order Type', required: false, example: 'delivery', group: 'order' },
    { field: 'notes', label: 'Notes / Instructions', required: false, example: 'Leave at back door', group: 'order' },
    { field: 'priority', label: 'Priority', required: false, example: '75', group: 'order' },
    // Pickup
    { field: 'pickup_name', label: 'Pickup Contact Name', required: false, example: 'Warehouse A', group: 'pickup' },
    { field: 'pickup_address', label: 'Pickup Address', required: true, example: '123 Main St, Sydney NSW 2000', group: 'pickup' },
    { field: 'pickup_lat', label: 'Pickup Latitude', required: false, example: '-33.8688', group: 'pickup' },
    { field: 'pickup_lng', label: 'Pickup Longitude', required: false, example: '151.2093', group: 'pickup' },
    { field: 'pickup_time_window_start', label: 'Pickup Window Start', required: false, example: '08:00', group: 'pickup' },
    { field: 'pickup_time_window_end', label: 'Pickup Window End', required: false, example: '12:00', group: 'pickup' },
    { field: 'pickup_service_time', label: 'Pickup Service Time (min)', required: false, example: '10', group: 'pickup' },
    // Dropoff
    { field: 'dropoff_name', label: 'Dropoff Contact Name', required: false, example: 'John Smith', group: 'dropoff' },
    { field: 'dropoff_address', label: 'Dropoff Address', required: true, example: '456 Queen St, Melbourne VIC 3000', group: 'dropoff' },
    { field: 'dropoff_lat', label: 'Dropoff Latitude', required: false, example: '-37.8136', group: 'dropoff' },
    { field: 'dropoff_lng', label: 'Dropoff Longitude', required: false, example: '144.9631', group: 'dropoff' },
    { field: 'dropoff_time_window_start', label: 'Delivery Window Start', required: false, example: '12:00', group: 'dropoff' },
    { field: 'dropoff_time_window_end', label: 'Delivery Window End', required: false, example: '17:00', group: 'dropoff' },
    { field: 'dropoff_service_time', label: 'Delivery Service Time (min)', required: false, example: '5', group: 'dropoff' },
    // Cargo / Capacity
    { field: 'weight_kg', label: 'Weight (kg)', required: false, example: '25', group: 'cargo' },
    { field: 'volume_m3', label: 'Volume (m³)', required: false, example: '0.5', group: 'cargo' },
    { field: 'pallets', label: 'Pallets', required: false, example: '2', group: 'cargo' },
    { field: 'parcels', label: 'Parcels / Packages', required: false, example: '10', group: 'cargo' },
    { field: 'required_skills', label: 'Required Skills (comma-separated)', required: false, example: 'refrigerated,tail_lift', group: 'cargo' },
    // Customer
    { field: 'customer_name', label: 'Customer Name', required: false, example: 'Acme Corp', group: 'customer' },
    { field: 'customer_phone', label: 'Customer Phone', required: false, example: '+61400000000', group: 'customer' },
    { field: 'customer_email', label: 'Customer Email', required: false, example: 'customer@example.com', group: 'customer' },
];

export default function fleetOpsOptions(key) {
    const allOptions = {
        driverTypes,
        driverStatuses,
        vehicleTypes,
        vehicleStatuses,
        vendorTypes,
        vendorStatuses,
        fleetTypes,
        fleetStatuses,
        contactTypes,
        contactStatuses,
        fuelReportTypes,
        fuelReportStatuses,
        issueTypes,
        issueStatuses,
        issueCategories,
        issueCategoriesPowerGroups: toPowerSelectGroups(issueCategories),
        issuePriorities,
        placeTypes,
        placeStatuses,
        routeProfileOptions,
        podOptions,
        serviceRateCalculationMethods,
        serviceRateCodCalculationMethods,
        serviceRatePeakHourCalculationMethods,
        distanceUnits,
        longDistanceUnits,
        dimensionUnits,
        weightUnits,
        serviceAreaTypes,
        telematicStatuses,
        telematicHealthStates,
        deviceTypes,
        sensorTypes,
        deviceStatuses,
        sensorStatuses,
        fuelTypes,
        fuelVolumeUnits,
        vehicleUsageTypes,
        vehicleOwnershipTypes,
        vehicleBodyTypes,
        vehicleBodySubTypes,
        transmissionTypes,
        odometerUnits,
        measurementSystems,
        // Orchestrator options
        driverSkills,
        driverSkillsPowerGroups: toPowerSelectGroups(driverSkills),
        vehicleSkills,
        vehicleSkillsPowerGroups: toPowerSelectGroups(vehicleSkills),
        capacityDimensions,
        vehicleCapacityProfiles,
        orderPriorityLevels,
        timeWindowPresets,
        optimizationObjectives,
        routingConstraintOptions,
        serviceTimePresets,
        importColumnMappings,
    };

    return allOptions[key] ?? [];
}
