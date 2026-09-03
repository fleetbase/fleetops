/**
 * Translations the addon renders but only the host console's `translations/en-us.yaml` defines.
 * Copied verbatim from the console so rendering tests see the strings a user sees. Keep this to
 * keys addon templates actually use; add to it as tests reach new ones.
 */
export default {
    common: {
        'edit-address': 'Edit Address',
        'new-address': 'New Address',
        online: 'Online',
        offline: 'Offline',
        'create-new-resource': 'Create new {resource}',
        'create-a-new-resource': 'Create a new {resource}',
        'view-resource-details': 'View {resource} Details',
        'edit-resource-details': 'Edit {resource} Details',
        'delete-resource': 'Delete {resource}',
    },
};
