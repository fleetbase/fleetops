import { htmlSafe } from '@ember/template';
import { isBlank } from '@ember/utils';

function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function line(content, className = '') {
    const classAttribute = className ? ` class="${className}"` : '';

    return `<div${classAttribute}>${escapeHtml(content)}</div>`;
}

export default function placeAddressHtml(place) {
    if (!place) {
        return htmlSafe('');
    }

    const name = place.name === place.street1 ? null : place.name;
    const cityStatePostalCode = [place.city, place.province, place.postal_code].filter((value) => !isBlank(value)).join(', ');
    const lines = [];

    if (name) {
        lines.push(line(name, 'font-semibold'));
        lines.push(line(place.street1));
    } else if (place.street1) {
        lines.push(line(place.street1, 'font-semibold'));
    }

    if (place.street2) {
        lines.push(line(place.street2));
    }

    lines.push(line(cityStatePostalCode));

    if (place.country) {
        lines.push(line(place.country_name));
    }

    return htmlSafe(`<address class="uppercase truncate w-full">${lines.join('')}</address>`);
}
