import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import titleize from 'ember-cli-string-helpers/utils/titleize';

function resolveStatusClass(status) {
    const statusKey = dasherize(status ?? 'default');

    return `status-badge ${statusKey}-status-badge status-badge-${statusKey}`;
}

function titleizeStatus(status) {
    if (!status) {
        return '-';
    }

    return titleize(`${status}`.replace(/[_-]+/g, ' '));
}

function buildStatusBadge(status, label = status) {
    return `
        <div class="${resolveStatusClass(status)} shadow-none ml-auto">
            <span class="rounded-full text-[9px] px-1.5 py-0.5">${titleizeStatus(label)}</span>
        </div>`;
}

function buildMetaCell(label, value, valueClass = '') {
    const shouldTruncate = valueClass.split(' ').includes('truncate');
    const flowClass = shouldTruncate ? 'min-w-0 w-full' : 'whitespace-normal break-words';

    return `
        <div class="rounded bg-gray-900 shadow-md px-1.5 py-1 min-w-0">
            <div class="text-[9px] font-semibold uppercase text-gray-400 leading-none">${label}</div>
            <div class="text-[11px] text-white leading-tight ${flowClass} ${valueClass}">${value ?? '-'}</div>
        </div>`;
}

function buildLiveMapCard(content, framed = false) {
    const frameClasses = framed ? 'relative rounded-lg bg-gray-900 shadow-lg p-1.5 text-white' : '';
    const closeButton = framed ? '<button type="button" class="fleetops-google-popover__close" data-fleetops-google-popover-close aria-label="Close">&times;</button>' : '';

    return `<div class="w-[280px] max-w-[calc(100vw-2rem)] ${frameClasses}">${closeButton}${content}</div>`;
}

function resolveDriverStatus(driver) {
    return driver.meta?.status_label ?? titleizeStatus(driver.status);
}

function resolveDriverLocation(driver) {
    return driver.meta?.location_coordinates ?? resolveLocationCoordinates(driver.location);
}

function resolveDriverSpeed(driver) {
    return driver.meta?.speed_label ?? `${driver.speed ?? '-'} km/h`;
}

function resolveDriverHeading(driver) {
    return driver.meta?.heading_label ?? driver.heading ?? '-';
}

function resolveDriverId(driver) {
    return driver.public_id ?? driver.id ?? '-';
}

function resolveVehicleNumber(vehicle) {
    return vehicle.internal_id ?? vehicle.public_id ?? vehicle.plate_number ?? vehicle.serial_number ?? vehicle.vin ?? '-';
}

function resolveVehicleStatus(vehicle) {
    return vehicle.meta?.status_label ?? vehicle.current_status ?? vehicle.status ?? '-';
}

function resolveVehicleLocation(vehicle) {
    return vehicle.meta?.location_coordinates ?? resolveLocationCoordinates(vehicle.location);
}

function resolveVehicleSpeed(vehicle) {
    return vehicle.meta?.speed_label ?? `${vehicle.speed ?? '-'} km/h`;
}

function resolveVehicleHeading(vehicle) {
    return vehicle.meta?.heading_label ?? vehicle.heading ?? '-';
}

function resolveLocationCoordinates(location) {
    if (!location) {
        return '-';
    }

    if (location?.coordinates && isArray(location.coordinates)) {
        const [lng, lat] = location.coordinates;
        return Number.isFinite(lat) && Number.isFinite(lng) ? `${formatCoordinate(lat)} ${formatCoordinate(lng)}` : '-';
    }

    if (isArray(location) && location.length >= 2) {
        const [lat, lng] = location;
        return Number.isFinite(lat) && Number.isFinite(lng) ? `${formatCoordinate(lat)} ${formatCoordinate(lng)}` : '-';
    }

    return '-';
}

function formatCoordinate(coordinate) {
    return `${Math.round(coordinate * 10000) / 10000}`;
}

export function buildDriverLiveMapContent(driver, framed = false) {
    const status = resolveDriverStatus(driver);
    const onlineClass = driver.online ? 'bg-green-400' : 'bg-red-500';

    return buildLiveMapCard(
        `
            <div class="fleetops-google-hover-tooltip__title mb-1.5 flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full ${onlineClass}"></span>
                <span>${driver.name ?? '-'}</span>
                ${buildStatusBadge(driver.status, status)}
            </div>
            <div class="grid grid-cols-2 gap-1">
                ${buildMetaCell('ID', resolveDriverId(driver))}
                ${buildMetaCell('Phone', driver.phone ?? '-')}
                ${buildMetaCell('Vehicle', driver.vehicle_name ?? '-')}
                ${buildMetaCell('Email', driver.email ?? '-', 'truncate')}
                ${buildMetaCell('Order', driver.meta?.current_order_reference ?? '-')}
                ${buildMetaCell('Speed', resolveDriverSpeed(driver))}
                ${buildMetaCell('Heading', resolveDriverHeading(driver))}
                ${buildMetaCell('Location', resolveDriverLocation(driver))}
            </div>
        `,
        framed
    );
}

export function buildVehicleLiveMapContent(vehicle, framed = false) {
    const status = resolveVehicleStatus(vehicle);
    const onlineClass = vehicle.online ? 'bg-green-400' : 'bg-red-500';

    return buildLiveMapCard(
        `
            <div class="fleetops-google-hover-tooltip__title mb-1.5 flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full ${onlineClass}"></span>
                <span>${vehicle.displayName ?? '-'}</span>
                ${buildStatusBadge(vehicle.status, status)}
            </div>
            <div class="grid grid-cols-2 gap-1">
                ${buildMetaCell('Vehicle #', resolveVehicleNumber(vehicle))}
                ${buildMetaCell('Driver', vehicle.driver_name ?? vehicle.driver?.name ?? '-')}
                ${buildMetaCell('Order', vehicle.meta?.current_order_reference ?? '-')}
                ${buildMetaCell('Speed', resolveVehicleSpeed(vehicle))}
                ${buildMetaCell('Heading', resolveVehicleHeading(vehicle))}
                ${buildMetaCell('Location', resolveVehicleLocation(vehicle))}
            </div>
        `,
        framed
    );
}

export function buildPlaceInfoWindowContent(place) {
    return `
        <div class="fleetops-google-popover fleetops-google-popover--compact">
            <div class="fleetops-google-popover__body">
                <div class="fleetops-google-popover__title">${place.name ?? place.address ?? '-'}</div>
                <div class="fleetops-google-popover__meta">${place.address ?? ''}</div>
            </div>
        </div>`;
}

export function buildPlaceTooltipContent(place) {
    return `
        <div class="fleetops-google-hover-tooltip__title">${place.name ?? place.address ?? '-'}</div>
        <div class="fleetops-google-hover-tooltip__meta">${place.address ?? ''}</div>
    `;
}
