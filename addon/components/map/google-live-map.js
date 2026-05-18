/* global google */
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { guidFor } from '@ember/object/internals';
import { dasherize } from '@ember/string';

function resolveStatusClass(status) {
    const statusKey = dasherize(status ?? 'default');

    return `status-badge ${statusKey}-status-badge status-badge-${statusKey}`;
}

function buildStatusBadge(status, label = status) {
    return `
        <div class="${resolveStatusClass(status)} shadow-none ml-auto">
            <span class="text-[9px] px-1.5 py-0.5">${label ?? '-'}</span>
        </div>`;
}

function buildMetaCell(label, value, valueClass = '') {
    return `
        <div class="rounded bg-gray-900 shadow-md px-1.5 py-1 min-w-0">
            <div class="text-[9px] font-semibold uppercase text-gray-400 leading-none">${label}</div>
            <div class="text-[11px] text-white leading-tight whitespace-normal break-words ${valueClass}">${value ?? '-'}</div>
        </div>`;
}

function resolveDriverStatus(driver) {
    return driver.meta?.status_label ?? driver.status ?? '-';
}

function resolveDriverLocation(driver) {
    return driver.meta?.location_coordinates ?? '-';
}

function resolveDriverSpeed(driver) {
    return driver.meta?.speed_label ?? `${driver.speed ?? '-'} km/h`;
}

function resolveDriverHeading(driver) {
    return driver.meta?.heading_label ?? driver.heading ?? '-';
}

function buildDriverLiveMapContent(driver) {
    const status = resolveDriverStatus(driver);
    const onlineClass = driver.online ? 'bg-green-400' : 'bg-red-500';

    return `
        <div class="w-[280px] max-w-[calc(100vw-2rem)]">
            <div class="fleetops-google-hover-tooltip__title mb-1.5 flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full ${onlineClass}"></span>
                <span>${driver.name ?? '-'}</span>
                ${buildStatusBadge(driver.status, status)}
            </div>
            <div class="grid grid-cols-2 gap-1">
                ${buildMetaCell('Phone', driver.phone ?? '-')}
                ${buildMetaCell('Vehicle', driver.vehicle_name ?? '-')}
                ${buildMetaCell('Speed', resolveDriverSpeed(driver))}
                ${buildMetaCell('Heading', resolveDriverHeading(driver))}
                ${buildMetaCell('Location', resolveDriverLocation(driver))}
            </div>
        </div>
    `;
}

function buildDriverInfoWindowContent(driver) {
    return buildDriverLiveMapContent(driver);
}

function buildDriverTooltipContent(driver) {
    return buildDriverLiveMapContent(driver);
}

function resolveVehicleNumber(vehicle) {
    return vehicle.internal_id ?? vehicle.public_id ?? vehicle.plate_number ?? vehicle.serial_number ?? vehicle.vin ?? '-';
}

function resolveVehicleStatus(vehicle) {
    return vehicle.meta?.status_label ?? vehicle.current_status ?? vehicle.status ?? '-';
}

function resolveVehicleLocation(vehicle) {
    return vehicle.meta?.location_coordinates ?? '-';
}

function resolveVehicleSpeed(vehicle) {
    return vehicle.meta?.speed_label ?? `${vehicle.speed ?? '-'} km/h`;
}

function resolveVehicleHeading(vehicle) {
    return vehicle.meta?.heading_label ?? vehicle.heading ?? '-';
}

function buildVehicleLiveMapContent(vehicle) {
    const status = resolveVehicleStatus(vehicle);
    const onlineClass = vehicle.online ? 'bg-green-400' : 'bg-red-500';

    return `
        <div class="w-[280px] max-w-[calc(100vw-2rem)]">
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
        </div>
    `;
}

function buildVehicleInfoWindowContent(vehicle) {
    return buildVehicleLiveMapContent(vehicle);
}

function buildVehicleTooltipContent(vehicle) {
    return buildVehicleLiveMapContent(vehicle);
}

function buildPlaceInfoWindowContent(place) {
    return `
        <div class="fleetops-google-popover fleetops-google-popover--compact">
            <div class="fleetops-google-popover__body">
                <div class="fleetops-google-popover__title">${place.name ?? place.address ?? '-'}</div>
                <div class="fleetops-google-popover__meta">${place.address ?? ''}</div>
            </div>
        </div>`;
}

function buildPlaceTooltipContent(place) {
    return `
        <div class="fleetops-google-hover-tooltip__title">${place.name ?? place.address ?? '-'}</div>
        <div class="fleetops-google-hover-tooltip__meta">${place.address ?? ''}</div>
    `;
}

export default class MapGoogleLiveMapComponent extends Component {
    @service mapManager;
    id = guidFor(this);
    @tracked map = null;
    _infoWindows = new Map();

    willDestroy() {
        super.willDestroy(...arguments);
        this._closeAllInfoWindows();
        this._infoWindows.clear();
        if (this.mapManager.providerName === 'google') {
            this.mapManager.destroyMap();
        }
    }

    @action async setupMap(element) {
        const map = await this.mapManager.initializeMap(element, {
            lat: this.args.latitude ?? 1.369,
            lng: this.args.longitude ?? 103.886,
            zoom: this.args.zoom ?? 12,
            disableDefaultUI: true,
            gestureHandling: 'greedy',
        });

        this.map = map;
        this.mapManager.on('moveend', () => this.args.onViewportChanged?.());
        this.mapManager.on('rightclick', (event) => this._showMapContextMenu(event));
        this.args.onLoad?.({ target: map });
        this.syncResources();
        debug('[GoogleLiveMap] Map initialised');
    }

    @action syncResources() {
        if (!this.map || !this.mapManager.isGoogleMaps) return;
        this.#syncDrivers();
        this.#syncVehicles();
        this.#syncPlaces();
        this.#syncServiceAreas();
    }

    async #syncDrivers() {
        const drivers = this.args.drivers ?? [];

        for (const driver of drivers) {
            const coords = this._getCoords(driver.location);
            if (!coords) continue;

            const marker = this.mapManager.getMarker(driver.id);
            if (!marker) {
                const createdMarker = await this.mapManager.addMarker(driver.id, coords.lat, coords.lng, {
                    iconUrl: driver.vehicle_avatar ?? '/engines-dist/images/driver-marker.png',
                    iconSize: [24, 24],
                    title: driver.name,
                    tooltip: buildDriverTooltipContent(driver),
                    tooltipOptions: { html: true },
                    rotationAngle: driver.heading,
                    onClick: () => this.#openInfoWindow(`driver:${driver.id}`, buildDriverInfoWindowContent(driver), driver.id, () => this.args.onDriverClicked?.(driver)),
                    onRightClick: (event) => this._showDriverContextMenu(driver, event),
                });

                this.args.onDriverAdded?.(driver, { target: createdMarker });
            } else {
                this.mapManager.updateMarkerPosition(driver.id, coords.lat, coords.lng, false, 0);
                if (Number.isFinite(driver.heading) && driver.heading !== -1) {
                    this.mapManager.setMarkerRotation(driver.id, driver.heading);
                }
            }
        }
    }

    async #syncVehicles() {
        const vehicles = this.args.vehicles ?? [];

        for (const vehicle of vehicles) {
            const coords = this._getCoords(vehicle.location);
            if (!coords) continue;

            const marker = this.mapManager.getMarker(vehicle.id);
            if (!marker) {
                const createdMarker = await this.mapManager.addMarker(vehicle.id, coords.lat, coords.lng, {
                    iconUrl: vehicle.avatar_url ?? '/engines-dist/images/vehicle-marker.png',
                    iconSize: [24, 24],
                    title: vehicle.displayName,
                    tooltip: buildVehicleTooltipContent(vehicle),
                    tooltipOptions: { html: true },
                    rotationAngle: vehicle.heading,
                    onClick: () => this.#openInfoWindow(`vehicle:${vehicle.id}`, buildVehicleInfoWindowContent(vehicle), vehicle.id, () => this.args.onVehicleClicked?.(vehicle)),
                    onRightClick: (event) => this._showVehicleContextMenu(vehicle, event),
                });

                this.args.onVehicleAdded?.(vehicle, { target: createdMarker });
            } else {
                this.mapManager.updateMarkerPosition(vehicle.id, coords.lat, coords.lng, false, 0);
                if (Number.isFinite(vehicle.heading) && vehicle.heading !== -1) {
                    this.mapManager.setMarkerRotation(vehicle.id, vehicle.heading);
                }
            }
        }
    }

    async #syncPlaces() {
        const places = this.args.places ?? [];

        for (const place of places) {
            const coords = this._getCoords(place.location);
            if (!coords) continue;

            const marker = this.mapManager.getMarker(place.id);
            if (!marker) {
                const createdMarker = await this.mapManager.addMarker(place.id, coords.lat, coords.lng, {
                    iconUrl: place.avatar_url ?? '/engines-dist/images/building-marker.png',
                    iconSize: [16, 16],
                    title: place.address,
                    tooltip: buildPlaceTooltipContent(place),
                    tooltipOptions: { html: true },
                    onClick: () => this.#openInfoWindow(`place:${place.id}`, buildPlaceInfoWindowContent(place), place.id, () => this.args.onPlaceClicked?.(place)),
                });

                this.args.onPlaceAdded?.(place, { target: createdMarker });
            } else {
                this.mapManager.updateMarkerPosition(place.id, coords.lat, coords.lng, false, 0);
            }
        }

        const activeMarkerIds = new Set([
            ...(this.args.drivers ?? []).map((driver) => driver.id),
            ...(this.args.vehicles ?? []).map((vehicle) => vehicle.id),
            ...places.map((place) => place.id),
        ]);

        this.#removeMarkersNotIn(activeMarkerIds);
    }

    #syncServiceAreas() {
        const serviceAreas = this.args.serviceAreas ?? [];
        const overlayIds = new Set();

        for (const serviceArea of serviceAreas) {
            const paths =
                this._coordinatesToGooglePaths(serviceArea?.border?.coordinates, { source: 'geojson' }) ??
                this._coordinatesToGooglePaths(serviceArea.leafletCoordinates, { source: 'leaflet' });
            if (!paths) continue;

            overlayIds.add(serviceArea.id);
            const polygon = this.mapManager.getOverlay(serviceArea.id);
            if (!polygon) {
                const createdPolygon = this.mapManager.addPolygon(serviceArea.id, paths, {
                    color: serviceArea.stroke_color ?? serviceArea.color ?? '#3388ff',
                    fillColor: serviceArea.color ?? '#3388ff',
                    fillOpacity: 0.2,
                    tooltip: serviceArea.name,
                    onRightClick: (event) => this._showServiceAreaContextMenu(serviceArea, event),
                });
                this.mapManager.hideLayer(createdPolygon);
                this.args.onServiceAreaLayerAdded?.(serviceArea, { target: createdPolygon });
            } else {
                polygon.setPaths(paths);
                polygon.__labelText = serviceArea.name;
                polygon.__labelPaths = paths;
            }

            for (const zone of serviceArea.zones ?? []) {
                const zonePaths =
                    this._coordinatesToGooglePaths(zone?.border?.coordinates, { source: 'geojson' }) ?? this._coordinatesToGooglePaths(zone.leafletCoordinates, { source: 'leaflet' });
                if (!zonePaths) continue;

                overlayIds.add(zone.id);
                const zonePolygon = this.mapManager.getOverlay(zone.id);
                if (!zonePolygon) {
                    const createdZonePolygon = this.mapManager.addPolygon(zone.id, zonePaths, {
                        color: zone.stroke_color ?? zone.color ?? '#3388ff',
                        fillColor: zone.color ?? '#3388ff',
                        fillOpacity: 0.2,
                        tooltip: zone.name,
                        onRightClick: (event) => this._showZoneContextMenu(zone, event),
                    });
                    this.mapManager.hideLayer(createdZonePolygon);
                    this.args.onZoneLayerAdd?.(zone, { target: createdZonePolygon });
                } else {
                    zonePolygon.setPaths(zonePaths);
                    zonePolygon.__labelText = zone.name;
                    zonePolygon.__labelPaths = zonePaths;
                }
            }
        }

        this.#removeStaleOverlays(overlayIds);
    }

    #removeMarkersNotIn(activeIds) {
        for (const [markerId] of this.mapManager.adapter?._markers ?? []) {
            if (this.#isTransientMarker(markerId)) {
                continue;
            }

            if (!activeIds.has(markerId)) {
                this._infoWindows.get(`driver:${markerId}`)?.close();
                this._infoWindows.delete(`driver:${markerId}`);
                this._infoWindows.get(`vehicle:${markerId}`)?.close();
                this._infoWindows.delete(`vehicle:${markerId}`);
                this._infoWindows.get(`place:${markerId}`)?.close();
                this._infoWindows.delete(`place:${markerId}`);
                this.mapManager.removeMarker(markerId);
            }
        }
    }

    #isTransientMarker(markerId) {
        return typeof markerId === 'string' && (markerId.startsWith('position-playback:') || markerId.startsWith('position-history:') || markerId.startsWith('route:'));
    }

    #removeStaleOverlays(activeIds) {
        for (const [overlayId, overlay] of this.mapManager.adapter?._overlays ?? []) {
            if (overlay?.__pinnedByFocus) {
                continue;
            }

            if (typeof overlayId === 'string' && overlayId.startsWith('route:')) {
                continue;
            }

            if (!activeIds.has(overlayId)) {
                this.mapManager.removeOverlay(overlayId);
            }
        }
    }

    #openInfoWindow(infoId, content, markerId, callback) {
        this._closeAllInfoWindows();

        let infoWindow = this._infoWindows.get(infoId);
        if (!infoWindow) {
            infoWindow = new google.maps.InfoWindow({ content });
            this._infoWindows.set(infoId, infoWindow);
        } else {
            infoWindow.setContent(content);
        }

        const marker = this.mapManager.getMarker(markerId);
        if (marker) {
            infoWindow.open({ map: this.map, anchor: marker });
        }

        callback?.();
    }

    _showMapContextMenu(event) {
        const items = this.mapManager.getComposedContextMenuItems('map');
        if (!items.length) return;
        this.mapManager.showContextMenu(event, items);
    }

    _showDriverContextMenu(driver, event) {
        const items = this.mapManager.getComposedContextMenuItems(`driver:${driver.public_id}`);
        this.mapManager.showContextMenu(event, items);
    }

    _showVehicleContextMenu(vehicle, event) {
        const items = this.mapManager.getComposedContextMenuItems(`vehicle:${vehicle.public_id}`);
        this.mapManager.showContextMenu(event, items);
    }

    _showServiceAreaContextMenu(serviceArea, event) {
        const items = this.mapManager.getComposedContextMenuItems(`service-area:${serviceArea.public_id}`);
        this.mapManager.showContextMenu(event, items);
    }

    _showZoneContextMenu(zone, event) {
        const items = this.mapManager.getComposedContextMenuItems(`zone:${zone.public_id}`);
        this.mapManager.showContextMenu(event, items);
    }

    _closeAllInfoWindows() {
        this._infoWindows.forEach((iw) => iw.close());
        this.mapManager.closeContextMenu();
    }

    _getCoords(location) {
        if (!location) return null;
        if (location?.coordinates && isArray(location.coordinates)) {
            const [lng, lat] = location.coordinates;
            if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
        }

        if (isArray(location) && location.length >= 2) {
            const [lat, lng] = location;
            if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
        }

        if (Number.isFinite(location?.latitude) && Number.isFinite(location?.longitude)) {
            return { lat: location.latitude, lng: location.longitude };
        }

        return null;
    }

    _coordinatesToGooglePaths(coordinates, options = {}) {
        if (!coordinates) return null;

        const source = options.source ?? 'leaflet';

        try {
            const rings = this._normalizeCoordinateRings(coordinates, source);
            const normalizedRings = rings.map((ring) => ring.map((point) => this._pointToGoogleLatLng(point, source)).filter(Boolean)).filter((ring) => ring.length >= 3);

            if (normalizedRings.length === 0) {
                return null;
            }

            return normalizedRings.length === 1 ? normalizedRings[0] : normalizedRings;
        } catch {
            return null;
        }
    }

    _normalizeCoordinateRings(coordinates, source = 'leaflet') {
        if (!isArray(coordinates) || coordinates.length === 0) {
            return [];
        }

        if (source === 'geojson') {
            const [firstPolygon] = coordinates;

            // Polygon coordinates: [ring[point]]
            if (isArray(firstPolygon) && typeof firstPolygon?.[0]?.[0] === 'number') {
                return [firstPolygon];
            }

            // MultiPolygon coordinates: [polygon[ring[point]]]
            if (isArray(firstPolygon) && isArray(firstPolygon?.[0])) {
                return coordinates.map((polygon) => (isArray(polygon) ? polygon[0] : null)).filter((ring) => isArray(ring));
            }

            return [];
        }

        // Flat leaflet-style ring: [[lat, lng], ...]
        if (typeof coordinates?.[0]?.[0] === 'number') {
            return [coordinates];
        }

        // Nested leaflet-style rings: [[[lat, lng], ...], ...]
        if (isArray(coordinates?.[0])) {
            return coordinates.filter((ring) => isArray(ring));
        }

        return [];
    }

    _pointToGoogleLatLng(point, source = 'leaflet') {
        if (typeof point?.lat === 'number' && typeof point?.lng === 'number') {
            return { lat: point.lat, lng: point.lng };
        }

        if (!isArray(point) || point.length < 2) {
            return null;
        }

        if (source === 'geojson') {
            const lng = Number(point[0]);
            const lat = Number(point[1]);
            return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        }

        const lat = Number(point[0]);
        const lng = Number(point[1]);
        return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    }
}
