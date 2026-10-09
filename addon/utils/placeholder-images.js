/**
 * The styled placeholder silhouettes now live in `@fleetbase/fleetops-data`,
 * where every extension that shows a driver, vehicle or contact can reach
 * them. This module keeps the FleetOps import path working.
 */
export { PLACEHOLDER_IMAGES, getPlaceholderImage, isDefaultImage, resolveResourceImage, default } from '@fleetbase/fleetops-data/utils/placeholder-images';
