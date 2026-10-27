import { underscore } from '@ember/string';

/**
 * Built-in routing providers governed by the admin routing provider policy
 * (Admin → Fleet-Ops → Routing). Engines registered by other extensions are not
 * listed here and are never filtered.
 */
export const GOVERNED_ROUTING_PROVIDERS = ['google', 'valhalla', 'osrm', 'vroom'];

/**
 * Whether the organization may use an engine, given the `allowed_providers`
 * list from its routing settings. Without that list nothing is filtered.
 */
export default function isRoutingProviderAllowed(name, allowedProviders) {
    const key = underscore(String(name ?? ''));
    if (!Array.isArray(allowedProviders) || !GOVERNED_ROUTING_PROVIDERS.includes(key)) {
        return true;
    }

    return allowedProviders.includes(key);
}
