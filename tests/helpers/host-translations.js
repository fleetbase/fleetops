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
        'upload-image': 'Upload Image',
        'upload-image-supported': 'Supports PNGs, JPEGs and GIFs',
        'select-field': 'Select {field}',
        type: 'Type',
        edit: 'Edit',
        total: 'Total',
        cancel: 'Cancel',
        avatar: 'Avatar',
        details: 'Details',
        name: 'Name',
        'resource-actions': '{resource} Actions',
        'view-resource': 'View {resource}',
        'edit-resource': 'Edit {resource}',
        'edit-resource-name': 'Edit: {resourceName}',
        'delete-resource-name': 'Delete: {resourceName}',
        address: 'Address',
        status: 'Status',
    },
    column: {
        address: 'Address',
        location: 'Location',
        vehicle: 'Vehicle',
        'last-seen': 'Last Seen',
    },
};
