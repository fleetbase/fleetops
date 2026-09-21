import { get } from '@ember/object';

/**
 * Shared helpers for the login account behind a driver, contact or customer
 * profile. The account itself is created, updated and removed by the server
 * from the profile's name/email/phone; the console only offers the account
 * actions (reset password, send credentials, deactivate/reactivate login).
 *
 * A profile owned by a staff member (`is_staff_linked`) has its login
 * managed from IAM, so no account actions are offered for it here.
 */

export const INTERNAL_NAMESPACE = 'int/v1';
export const INACTIVE_LOGIN_STATUSES = ['inactive', 'disabled', 'suspended'];

function read(subject, key) {
    if (!subject) {
        return undefined;
    }

    try {
        return get(subject, key);
    } catch {
        return subject[key];
    }
}

/**
 * The linked user record when it is already loaded, without triggering a
 * fetch for an async relationship.
 */
export function loadedUser(profile) {
    if (typeof profile?.belongsTo === 'function') {
        try {
            return profile.belongsTo('user').value();
        } catch {
            return null;
        }
    }

    const user = read(profile, 'user');
    if (!user) {
        return null;
    }

    return typeof user.then === 'function' ? (read(user, 'content') ?? null) : user;
}

export function isStaffLinked(profile) {
    return Boolean(read(profile, 'is_staff_linked'));
}

export function hasLinkedUser(profile) {
    if (!profile) {
        return false;
    }

    if (read(profile, 'user_uuid') || read(profile, 'login_status')) {
        return true;
    }

    const user = loadedUser(profile);
    return Boolean(user && (read(user, 'id') || read(user, 'uuid')));
}

/**
 * True when the profile has a login account that is managed from Fleet-Ops,
 * i.e. a linked user which is not a staff member's account.
 */
export function hasManagedLogin(profile) {
    return hasLinkedUser(profile) && !isStaffLinked(profile);
}

/**
 * The login status, preferring the server-provided `login_status` over the
 * embedded user's status.
 */
export function linkedUserStatus(profile) {
    const loginStatus = read(profile, 'login_status');
    if (loginStatus) {
        return loginStatus;
    }

    const user = loadedUser(profile);
    return (user ? (read(user, 'status') ?? read(user, 'session_status')) : null) ?? 'active';
}

export function hasInactiveLogin(profile) {
    return INACTIVE_LOGIN_STATUSES.includes(linkedUserStatus(profile));
}

export function withoutIdentityFields(payload = {}) {
    const attributes = { ...payload };

    delete attributes.id;
    delete attributes.uuid;
    delete attributes.public_id;

    return attributes;
}

/**
 * Applies the profile payload returned by an account action endpoint
 * (`{ driver: {...} }`, `{ customer: {...} }` or `{ contact: {...} }`).
 */
export function updateProfileFromResponse(profile, response = {}) {
    const payload = response?.driver ?? response?.customer ?? response?.contact;

    if (!payload || typeof profile?.setProperties !== 'function') {
        return;
    }

    const attributes = {};
    if ('user_uuid' in payload) {
        attributes.user_uuid = payload.user_uuid;
    }
    if ('is_staff_linked' in payload) {
        attributes.is_staff_linked = Boolean(payload.is_staff_linked);
    }
    if ('login_status' in payload) {
        attributes.login_status = payload.login_status;
    } else if (payload.user && 'status' in payload.user) {
        attributes.login_status = payload.user.status;
    }

    profile.setProperties(attributes);

    if (payload.user) {
        const user = loadedUser(profile);

        if (typeof user?.setProperties === 'function') {
            user.setProperties(withoutIdentityFields(payload.user));
        }
    }
}

/**
 * Opens a confirm modal which POSTs to an account action endpoint and applies
 * the response to the profile.
 *
 * @param {Object} services { modalsManager, fetch, notifications }
 * @param {Object} profile the driver or contact record
 * @param {Object} options { title, body, acceptButtonText, acceptButtonScheme, endpoint, payload, successMessage, onSuccess }
 */
export function confirmLoginAction({ modalsManager, fetch, notifications }, profile, options = {}) {
    const { title, body, acceptButtonText, acceptButtonScheme, endpoint, payload = {}, successMessage, onSuccess } = options;

    return modalsManager.confirm({
        title,
        body,
        acceptButtonText,
        acceptButtonScheme,
        confirm: async (modal) => {
            modal.startLoading();

            try {
                const response = await fetch.post(endpoint, payload, { namespace: INTERNAL_NAMESPACE });
                updateProfileFromResponse(profile, response);
                notifications.success(successMessage);

                if (typeof onSuccess === 'function') {
                    onSuccess(response);
                }

                modal.done();
            } catch (error) {
                notifications.serverError(error);
                modal.stopLoading();
            }
        },
    });
}
