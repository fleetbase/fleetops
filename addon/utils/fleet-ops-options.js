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
    };

    return allOptions[key] ?? [];
}
