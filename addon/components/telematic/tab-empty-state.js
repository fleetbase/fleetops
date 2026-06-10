import Component from '@glimmer/component';

export default class TelematicTabEmptyStateComponent extends Component {
    get toneClass() {
        switch (this.args.tone) {
            case 'success':
                return 'border-green-200 bg-green-50 text-green-900 dark:border-green-700 dark:bg-green-900/20 dark:text-green-100';
            case 'warning':
                return 'border-yellow-200 bg-yellow-50 text-yellow-900 dark:border-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-100';
            case 'danger':
                return 'border-red-200 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-900/20 dark:text-red-100';
            case 'info':
                return 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-100';
            default:
                return 'border-gray-200 bg-white text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
        }
    }

    get iconClass() {
        switch (this.args.tone) {
            case 'success':
                return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
            case 'warning':
                return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300';
            case 'danger':
                return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
            case 'info':
                return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
            default:
                return 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
        }
    }
}
