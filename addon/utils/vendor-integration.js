import apiUrl from '@fleetbase/ember-core/utils/api-url';

/**
 * Normalize any array-like (EmberArray, Set, arguments) into a plain array.
 * @template T
 * @param {T[] | { toArray?: () => T[] } | Iterable<T> | null | undefined} input
 * @returns {T[]}
 */
export function normalizeToArray(input) {
    if (!input) return [];
    if (Array.isArray(input)) return input;
    if (typeof input.toArray === 'function') return input.toArray();
    try {
        return Array.from(input);
    } catch {
        return [];
    }
}

/**
 * Derive a usable key from a param entry that may be a string or object.
 * @param {unknown} item
 * @param {string} [fallback]
 * @returns {string | undefined}
 */
export function extractKey(item, fallback) {
    if (typeof item === 'string') return item.trim() || fallback;
    if (item && typeof item === 'object') {
        // prefer explicit `key`, then common alternates
        const k = /** @type {any} */ (item).key ?? /** @type {any} */ (item).name ?? /** @type {any} */ (item).code ?? fallback;
        return typeof k === 'string' ? k : fallback;
    }
    return fallback;
}

/**
 * From a list of params (strings or objects), build an object map { key: null }.
 * @param {unknown} params
 * @param {{ keyFallback?: string }} [opts]
 * @returns {Record<string, null>}
 */
export function buildNullObject(params, opts = {}) {
    const { keyFallback } = opts;
    const arr = normalizeToArray(params);
    const entries = arr
        .map((p) => extractKey(p, keyFallback))
        .filter((k) => typeof k === 'string' && k.length > 0)
        .map((k) => [k, null]);

    return Object.fromEntries(entries);
}

/**
 * Build the webhook URL for an integration.
 * @param {string} providerCode
 * @returns {string}
 */
export function buildWebhookUrl(providerCode) {
    return apiUrl(`listeners/${providerCode}`);
}

/**
 * Validate provider shape and return a normalized view.
 * @param {any} provider
 * @returns {{ code: string, credentialParams: any[], optionParams: any[] } | null}
 */
export function normalizeProvider(provider) {
    if (!provider || typeof provider !== 'object') return null;

    const code = /** @type {any} */ (provider).code;
    if (!code || typeof code !== 'string') return null;

    const credentialParams = normalizeToArray(/** @type {any} */ (provider).credential_params);
    const optionParams = normalizeToArray(/** @type {any} */ (provider).option_params);

    return { code, credentialParams, optionParams };
}

/**
 * Build the payload used to create an `integrated-vendor` record.
 * @param {{ code: string, credentialParams: any[], optionParams: any[] }} normalized
 * @param {(path: string) => string} apiUrlFn
 * @returns {{
 *  provider: string,
 *  webhook_url: string,
 *  credentials: Record<string, null>,
 *  options: Record<string, null>,
 *  credential_params: any[],
 *  option_params: any[]
 * }}
 */
export function buildIntegrationPayload(normalized) {
    const { code, credentialParams, optionParams } = normalized;

    const credentials = buildNullObject(credentialParams, { keyFallback: 'credential' });
    const options = buildNullObject(optionParams, { keyFallback: 'option' });

    return {
        provider: code,
        webhook_url: buildWebhookUrl(code),
        credentials,
        options,
        credential_params: credentialParams,
        option_params: optionParams,
    };
}
